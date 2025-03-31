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
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        
        logger.debug(f"Checking for user with email: {email}")
        cursor.execute("SELECT ID, user_email FROM wp_users WHERE user_email = %s", (email,))
        user = cursor.fetchone()
        
        if not user:
            logger.warning(f"No user found with email: {email}")
            return False
            
        logger.debug(f"Found user: ID={user['ID']}, Email={user['user_email']}")
        
        # Count existing meta entries
        cursor.execute("SELECT COUNT(*) as meta_count FROM wp_usermeta WHERE user_id = %s", (user['ID'],))
        meta_count = cursor.fetchone()['meta_count']
        logger.debug(f"Found {meta_count} meta entries to delete")
        
        # Perform deletion
        cursor.execute("DELETE FROM wp_usermeta WHERE user_id = %s", (user['ID'],))
        cursor.execute("DELETE FROM wp_users WHERE ID = %s", (user['ID'],))
        conn.commit()
        
        logger.info(f"Deleted user ID {user['ID']} ({email}) with {meta_count} meta entries")
        return True
        
    except Exception as e:
        logger.error(f"Deletion error: {str(e)}", exc_info=True)
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
        
        # Debug: Print the entire XML structure
        logger.debug(f"Full XML structure: {ET.tostring(root, encoding='unicode')}")
        
        # Find email using more flexible approach
        email = None
        for possible_tag in ['Email', 'EmailAddress', 'email', 'email_address']:
            element = root.find(possible_tag)
            if element is not None and element.text:
                email = element.text.strip()
                break
                
        if not email:
            raise ValueError(f"No valid email field found in XML. Tried: Email, EmailAddress, email, email_address")
            
        # Verify action type
        action_type_element = root.find('ActionType')
        if not action_type_element:
            raise ValueError("No ActionType field found in XML")
            
        action_type = action_type_element.text.strip().upper()
        if action_type != 'DELETE':
            raise ValueError(f"Invalid action type: {action_type} (expected DELETE)")
            
        return email
        
    except Exception as e:
        logger.error(f"XML parsing failed. Original message: {xml_data}")
        raise ValueError(f"XML parsing error: {str(e)}")

def on_message(channel, method, properties, body):
    try:
        logger.info(f"Received message from {method.routing_key}")
        xml_data = body.decode()
        
        try:
            email = parse_xml_message(xml_data)
            if delete_wordpress_user(email):
                logger.info(f"Successfully processed deletion for {email}")
            else:
                logger.warning(f"No user found with email: {email}")
            channel.basic_ack(method.delivery_tag)
            
        except ValueError as e:
            logger.error(f"Invalid message format: {e}\nMessage: {xml_data}")
            channel.basic_nack(method.delivery_tag, requeue=False)
            
    except Exception as e:
        logger.error(f"Unexpected processing error: {str(e)}", exc_info=True)
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