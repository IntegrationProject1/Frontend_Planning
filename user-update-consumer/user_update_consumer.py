import pika
import os
import logging
import xml.etree.ElementTree as ET
import mysql.connector
from datetime import datetime

# Configure logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Database connection for WordPress
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

# Get current WordPress user data
def get_current_user_data(email):
    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        
        cursor.execute("""
            SELECT * FROM wp_users 
            WHERE user_email = %s
        """, (email,))
        
        result = cursor.fetchone()
        if not result:
            raise ValueError(f"No user found with email: {email}")
            
        return result
        
    except Exception as e:
        logger.error(f"Failed to fetch current user data: {e}")
        raise
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

# Update WordPress user data 
def update_user(user_data):
    conn = None
    cursor = None
    try:
        if not user_data.get('EmailAddress'):
            raise ValueError("EmailAddress is required for update")
            
        current_data = get_current_user_data(user_data['EmailAddress'])
        
        update_fields = {
            'first_name': user_data.get('FirstName') or current_data.get('first_name'),
            'last_name': user_data.get('LastName') or current_data.get('last_name'),
            'phone_number': user_data.get('PhoneNumber') or current_data.get('phone_number'),
            'time_of_action': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'action_type': 'UPDATE'
        }
        
        business = user_data.get('Business')
        if business:
            update_fields.update({
                'business_name': business.get('BusinessName') or current_data.get('business_name'),
                'business_email': business.get('BusinessEmail') or current_data.get('business_email'),
                'real_address': business.get('RealAddress') or current_data.get('real_address'),
                'btw_number': business.get('BTWNumber') or current_data.get('btw_number'),
                'facturation_address': business.get('FacturationAddress') or current_data.get('facturation_address')
            })
        
        conn = get_db_connection()
        cursor = conn.cursor()
        
        set_clauses = []
        values = []
        for field, value in update_fields.items():
            if value is not None:
                set_clauses.append(f"{field} = %s")
                values.append(value)
        
        values.append(user_data['EmailAddress'])
        
        update_query = f"""
        UPDATE wp_users SET
            {', '.join(set_clauses)}
        WHERE user_email = %s
        """
        
        cursor.execute(update_query, tuple(values))
        conn.commit()
        logger.info(f"Updated WordPress user with email: {user_data['EmailAddress']}")
        return True
        
    except Exception as e:
        logger.error(f"User update failed: {e}")
        if conn:
            conn.rollback()
        raise
    finally:
        if cursor:
            cursor.close()
        if conn and conn.is_connected():
            conn.close()

# Parse XML data to extract user information
def parse_user_xml(xml_data):
    try:
        root = ET.fromstring(xml_data)
        
        user_data = {
            'ActionType': root.find('ActionType').text,
            'UserID': root.find('UserID').text,
            'TimeOfAction': root.find('TimeOfAction').text if root.find('TimeOfAction') is not None else None
        }
        
        # Optional fields
        if root.find('FirstName') is not None:
            user_data['FirstName'] = root.find('FirstName').text
        if root.find('LastName') is not None:
            user_data['LastName'] = root.find('LastName').text
        if root.find('PhoneNumber') is not None:
            user_data['PhoneNumber'] = root.find('PhoneNumber').text
        if root.find('EmailAddress') is not None:
            user_data['EmailAddress'] = root.find('EmailAddress').text
            
        # Business data
        business = root.find('Business')
        if business is not None:
            business_data = {}
            if business.find('BusinessName') is not None:
                business_data['BusinessName'] = business.find('BusinessName').text
            if business.find('BusinessEmail') is not None:
                business_data['BusinessEmail'] = business.find('BusinessEmail').text
            if business.find('RealAddress') is not None:
                business_data['RealAddress'] = business.find('RealAddress').text
            if business.find('BTWNumber') is not None:
                business_data['BTWNumber'] = business.find('BTWNumber').text
            if business.find('FacturationAddress') is not None:
                business_data['FacturationAddress'] = business.find('FacturationAddress').text
            
            user_data['Business'] = business_data
        
        return user_data
    except Exception as e:
        logger.error(f"XML parsing failed: {e}")
        raise

# Handle incoming RabbitMQ messages
def on_message(channel, method, properties, body):
    try:
        logger.info(f"Received update message from {method.routing_key}")
        
        user_data = parse_user_xml(body.decode())
        
        if user_data['ActionType'].upper() != 'UPDATE':
            logger.warning(f"Ignoring non-UPDATE action: {user_data['ActionType']}")
            channel.basic_ack(method.delivery_tag)
            return
            
        update_user(user_data)
        channel.basic_ack(method.delivery_tag)
        
    except Exception as e:
        logger.error(f"Message processing failed: {e}")
        channel.basic_nack(method.delivery_tag, requeue=False)

# Start the RabbitMQ consumer
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
        queues = ['crm_user_update', 'facturatie_user_update', 'kassa_user_update']
        for queue in queues:
            channel.queue_declare(queue=queue, durable=True)
            channel.basic_consume(
                queue=queue,
                on_message_callback=on_message,
                auto_ack=False
            )
        
        logger.info("Waiting for user update messages...")
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