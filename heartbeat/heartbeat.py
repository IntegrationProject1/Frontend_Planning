import pika
import os
import logging
import time

from datetime import datetime

# Logger setup
logger = logging.getLogger("HeartbeatLogger")
logger.setLevel(logging.INFO)
handler = logging.StreamHandler()
handler.setFormatter(logging.Formatter('%(asctime)s - %(levelname)s - %(message)s'))
logger.addHandler(handler)

# RabbitMQ settings
MQ_SERVER = os.getenv('RABBITMQ_HOST', 'localhost')
MQ_PORT = int(os.getenv('RABBITMQ_PORT', 5672))
MQ_USER = os.getenv('RABBITMQ_USER', 'guest')
MQ_PASS = os.getenv('RABBITMQ_PASSWORD', 'guest')
MQ_VHOST = os.getenv('MQ_VHOST', '/')

# Heartbeat settings
SERVICE_ID = 'frontend_heartbeat'
INSTANCE_NAME = os.getenv('INSTANCE_NAME', 'heartbeat_service')
EXCHANGE = 'heartbeat_monitoring'
QUEUE = 'controlroom.heartbeat.ping'
ROUTING = 'controlroom.heartbeat.ping'

# XML message formatter conform XSD
def dict_to_xml(log):
    return f"""
    <Heartbeat>
        <ServiceName>{log['ServiceName']}</ServiceName>
    </Heartbeat>
    """.strip()

def get_heartbeat_message():
    return dict_to_xml({
        'ServiceName': SERVICE_ID
    })

# RabbitMQ connectie met retries
def setup_rabbitmq_channel():
    max_retries = 10
    retry_delay = 5
    for attempt in range(max_retries):
        try:
            logger.info(f"ENV - RABBITMQ_HOST={os.getenv('RABBITMQ_HOST')}")
            logger.info(f"ENV - RABBITMQ_PORT={os.getenv('RABBITMQ_PORT')}")
            logger.info(f"ENV - RABBITMQ_USER={os.getenv('RABBITMQ_USER')}")
            logger.info(f"ENV - RABBITMQ_PASSWORD={os.getenv('RABBITMQ_PASSWORD')}")

            auth = pika.PlainCredentials(username=MQ_USER, password=MQ_PASS)
            params = pika.ConnectionParameters(
                host=MQ_SERVER,
                port=MQ_PORT,
                virtual_host=MQ_VHOST,
                credentials=auth
            )
            logger.info(f"Trying RabbitMQ connection to {MQ_SERVER}:{MQ_PORT} | user={MQ_USER} | vhost={MQ_VHOST}")
            conn = pika.BlockingConnection(params)
            chan = conn.channel()
            logger.info("Verbinding met RabbitMQ succesvol gevestigd")
            return conn, chan
        except Exception as error:
            logger.error(f"Fout bij het verbinden met RabbitMQ (poging {attempt + 1}/{max_retries}): {str(error)}")
            if attempt < max_retries - 1:
                time.sleep(retry_delay)
            else:
                raise

# Heartbeat loop
def run_heartbeat():
    connection, channel = setup_rabbitmq_channel()

    logger.info(f"Heartbeat-service gestart voor instance '{INSTANCE_NAME}'")
    try:
        while True:
            heartbeat_msg = get_heartbeat_message()
            channel.basic_publish(
                exchange=EXCHANGE,
                routing_key=ROUTING,
                body=heartbeat_msg.encode('utf-8'),
                properties=pika.BasicProperties(delivery_mode=2)
            )
            logger.info("Heartbeat-bericht verzonden naar monitoring")
            time.sleep(1)
    except KeyboardInterrupt:
        logger.warning("Heartbeat-service gestopt door gebruiker")
    except Exception as error:
        logger.error(f"Er is een fout opgetreden: {str(error)}")
    finally:
        logger.info("Verbinding met RabbitMQ wordt gesloten")
        connection.close()


def start():
    logger.info("Initialiseren van de heartbeat-service...")
    run_heartbeat()

if __name__ == "__main__":
    start()