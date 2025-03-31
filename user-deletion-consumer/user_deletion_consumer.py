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
        
        # Get user ID
        cursor.execute("SELECT ID FROM wp_users WHERE user_email = %s", (email,))
        user = cursor.fetchone()
        
        if not user:
            logger.warning(f"No user found with email: {email}")
            return False
            
        user_id = user['ID']
        
        # Delete user meta
        cursor.execute("DELETE FROM wp_usermeta WHERE user_id = %s", (user_id,))
        # Delete user
        cursor.execute("DELETE FROM wp_users WHERE ID = %s", (user_id,))
        
        conn.commit()
        logger.info(f"Deleted user ID {user_id} ({email})")
        return True
        
    except Exception as e:
        logger.error(f"Deletion failed for {email}: {str(e)}")
        if conn:
            conn.rollback()
        raise
    finally:
        if cursor:
            cursor.close()
        if conn:
            conn.close()

def parse_xml_message(xml_data):
    try:
        root = ET.fromstring(xml_data)
        email = root.find('Email').text  # Directly access the Email tag
        action_type = root.find('ActionType').text
        
        if not email:
            raise ValueError("Email is empty")
            
        if action_type.upper() != 'DELETE':
            raise ValueError(f"Ignoring non-DELETE action: {action_type}")
            
        return email
        
    except Exception as e:
        logger.error(f"XML parsing failed. Error: {str(e)}\nXML Content: {xml_data}")
        raise

def on_message(channel, method, properties, body):
    try:
        logger.info(f"Received deletion message from {method.routing_key}")
        xml_data = body.decode()
        
        # Direct parsing without over-engineering
        root = ET.fromstring(xml_data)
        email = root.find('Email').text
        action_type = root.find('ActionType').text
        
        if action_type.upper() != 'DELETE':
            logger.warning(f"Ignoring non-DELETE action: {action_type}")
            channel.basic_ack(method.delivery_tag)
            return
            
        logger.info(f"Processing deletion for: {email}")
        
        if delete_wordpress_user(email):
            logger.info(f"Successfully deleted user: {email}")
        else:
            logger.warning(f"User not found: {email}")
            
        channel.basic_ack(method.delivery_tag)
        
    except Exception as e:
        logger.error(f"Failed to process message: {str(e)}\nRaw message: {body.decode()}")
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
        
        logger.info(f"Listening for deletion messages on queues: {', '.join(queues)}")
        channel.start_consuming()
        
    except KeyboardInterrupt:
        logger.info("Stopping consumer...")
        channel.stop_consuming()
        connection.close()
        logger.info("Consumer stopped.")
    except Exception as e:
        logger.error(f"Consumer failed: {e}")
        raise

if __name__ == "__main__":
    start_consumer()