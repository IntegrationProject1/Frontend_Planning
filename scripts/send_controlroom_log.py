from dotenv import load_dotenv
import os
import pika

# Load environment variables from .env file
load_dotenv()

RABBITMQ_HOST = os.getenv("RABBITMQ_HOST")
RABBITMQ_PORT = int(os.getenv("RABBITMQ_PORT"))
RABBITMQ_USER = os.getenv("RABBITMQ_USER")
RABBITMQ_PASSWORD = os.getenv("RABBITMQ_PASSWORD")

print(f"✅ Starting log sender...")
print(f"🔧 Loaded env: HOST={RABBITMQ_HOST}, PORT={RABBITMQ_PORT}, USER={RABBITMQ_USER}")

def build_log_xml(service_name, status, message):
    """
    Build a valid XML log message according to the XSD schema.
    """
    xml = "<Log>"
    xml += f"<ServiceName>{service_name}</ServiceName>"
    xml += f"<Status>{status}</Status>"
    xml += f"<Message>{message}</Message>"
    xml += "</Log>"
    return xml

def send_log_to_controlroom(xml_message):
    """
    Send the XML log message to RabbitMQ using the correct exchange and routing key.
    """
    print("📡 Connecting to RabbitMQ...")
    connection = pika.BlockingConnection(pika.ConnectionParameters(
        host=RABBITMQ_HOST,
        port=RABBITMQ_PORT,
        credentials=pika.PlainCredentials(RABBITMQ_USER, RABBITMQ_PASSWORD)
    ))

    channel = connection.channel()

    print("📤 Declaring exchange 'log_monitoring' and sending message...")
    channel.exchange_declare(exchange='log_monitoring', exchange_type='direct', durable=True)

    channel.basic_publish(
        exchange='log_monitoring',
        routing_key='controlroom.log.event',
        body=xml_message
    )

    print("✅ Log sent to Controlroom.")
    connection.close()

if __name__ == "__main__":
    xml = build_log_xml(
        service_name="Frontend",  # يجب أن تكون بنفس اسم heartbeat
        status="SUCCESS",         # status in capital letters
        message="User created successfully"
    )
    print("🛠 XML Message built:")
    print(xml)
    send_log_to_controlroom(xml)
