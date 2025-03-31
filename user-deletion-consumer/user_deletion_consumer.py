# user-deletion-consumer/app.py
import pika
import os
import logging
import xml.etree.ElementTree as ET
import mysql.connector

# Configure logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

def get_db_connection():
    try:
        return mysql.connector.connect(
            host=os.getenv("DB_HOST"),
            user=os.getenv("DB_USER"),
            password=os.getenv("DB_PASSWORD"),
            database=os.getenv("DB_NAME")
        )
    except Exception as e:
        logger.error(f"Database connection failed: {e}")
        raise

def delete_wordpress_user(email):
    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        
        # First verify user exists
        cursor.execute("SELECT ID FROM wp_users WHERE user_email = %s", (email,))
        user = cursor.fetchone()
        
        if not user:
            logger.warning(f"No user found with email: {email}")
            return False
            
        user_id = user['ID']
        
        # Delete from wp_usermeta first (to maintain referential integrity)
        cursor.execute("DELETE FROM wp_usermeta WHERE user_id = %s", (user_id,))
        
        # Then delete from wp_users
        cursor.execute("DELETE FROM wp_users WHERE ID = %s", (user_id,))
        
        conn.commit()
        logger.info(f"Deleted WordPress user: {email} (ID: {user_id})")
        return True
        
    except Exception as e:
        logger.error(f"Deletion failed for {email}: {e}")
        if conn:
            conn.rollback()
        raise
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

def parse_user_xml(xml_data):
    try:
        root = ET.fromstring(xml_data)
        
        # Extract required fields
        user_data = {
            'action_type': root.find('ActionType').text,
            'email': root.find('Email').text  # Changed from EmailAddress to Email
        }
        
        return user_data
    except Exception as e:
        logger.error(f"XML parsing failed: {e}\nXML Content: {xml_data}")
        raise

def on_message(channel, method, properties, body):
    try:
        logger.info(f"Received message from {method.routing_key}")
        
        user_data = parse_user_xml(body.decode())
        
        if user_data['action_type'].upper() != 'DELETE':
            logger.warning(f"Ignoring non-DELETE action: {user_data['action_type']}")
            channel.basic_ack(method.delivery_tag)
            return
            
        if delete_wordpress_user(user_data['email']):
            logger.info(f"Successfully processed deletion for {user_data['email']}")
        else:
            logger.warning(f"No user found to delete: {user_data['email']}")
            
        channel.basic_ack(method.delivery_tag)
        
    except Exception as e:
        logger.error(f"Message processing failed: {e}\nRaw message: {body.decode()}")
        channel.basic_nack(method.delivery_tag, requeue=False)

def start_consumer():
    connection = pika.BlockingConnection(pika.ConnectionParameters(
        host=os.getenv("RABBITMQ_HOST"),
        port=int(os.getenv("RABBITMQ_PORT")),
        credentials=pika.PlainCredentials(
            os.getenv("RABBITMQ_USER"),
            os.getenv("RABBITMQ_PASSWORD")
        )
    ))
    channel = connection.channel()
    
    try:
        queues = ['facturatie_user_delete', 'crm_user_delete', 'kassa_user_delete']
        for queue in queues:
            channel.queue_declare(queue=queue, durable=True)
            channel.basic_consume(
                queue=queue,
                on_message_callback=on_message,
                auto_ack=False
            )
        
        logger.info(f"Listening on queues: {', '.join(queues)}")
        channel.start_consuming()
        
    except KeyboardInterrupt:
        logger.info("Stopping consumer...")
        channel.stop_consuming()
    except Exception as e:
        logger.error(f"Consumer failed: {e}")
        raise
    finally:
        connection.close()

if __name__ == "__main__":
    start_consumer()