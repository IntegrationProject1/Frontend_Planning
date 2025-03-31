import pika
import os
import logging
import xml.etree.ElementTree as ET
import mysql.connector
from datetime import datetime

# Set up logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

def user_exists(email):

    conn = mysql.connector.connect(
        host=os.getenv("DB_HOST"),
        user=os.getenv("DB_USER"),
        password=os.getenv("DB_PASSWORD"),
        database=os.getenv("DB_NAME")
    )
    cursor = conn.cursor()
    
    try:
        cursor.execute("SELECT ID FROM wp_users WHERE user_email = %s", (email,))
        return cursor.fetchone() is not None
    except Exception as e:
        logger.error(f"Error checking user existence: {e}")
        raise
    finally:
        cursor.close()
        conn.close()

def extract_city(address):

    parts = address.split(',')
    if len(parts) >= 3:
        return parts[-2].strip()
    return ''

def extract_postcode(address):

    parts = address.split(',')
    if len(parts) >= 3:
        return parts[-1].strip().split(' ')[0]
    return ''

def create_wp_user(user_data):

    conn = mysql.connector.connect(
        host=os.getenv("DB_HOST"),
        user=os.getenv("DB_USER"),
        password=os.getenv("DB_PASSWORD"),
        database=os.getenv("DB_NAME")
    )
    cursor = conn.cursor()
    
    try:
        # Check if user already exists
        if user_exists(user_data['email']):
            logger.warning(f"User with email {user_data['email']} already exists")
            return False
        
        # Generate WordPress-friendly username (email without @domain)
        username = user_data['email'].split('@')[0]
        
        # Prepare data for WordPress schema
        now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        
        insert_query = """
        INSERT INTO wp_users (
            user_login, user_pass, user_nicename, user_email,
            user_registered, user_status, display_name, first_name,
            last_name, phone_number, business_name, business_email,
            real_address, btw_number, facturation_address, time_of_action,
            action_type
        ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """
        
        # Execute with WordPress-specific fields
        cursor.execute(insert_query, (
            username,                              # user_login
            '',                                    # user_pass (empty, can be set later)
            username,                              # user_nicename
            user_data['email'],                    # user_email
            now,                                  # user_registered
            0,                                    # user_status
            f"{user_data['first_name']} {user_data['last_name']}",  # display_name
            user_data['first_name'],               # first_name
            user_data['last_name'],               # last_name
            user_data['phone'],                   # phone_number
            user_data['business_name'],           # business_name
            user_data['business_email'],          # business_email
            user_data['address'],                  # real_address
            user_data['vat_number'],               # btw_number
            user_data['billing_address'],         # facturation_address
            user_data['action_time'],             # time_of_action
            user_data['action_type']              # action_type
        ))
        
        conn.commit()
        logger.info(f"Created new WordPress user: {user_data['email']}")
        return True
        
    except Exception as e:
        logger.error(f"User creation failed: {e}")
        conn.rollback()
        raise
    finally:
        cursor.close()
        conn.close()

def parse_user_xml(xml_data):

    try:
        root = ET.fromstring(xml_data)
        business = root.find('Business')
        
        return {
            'action_type': root.find('ActionType').text,
            'user_id': root.find('UserID').text,
            'action_time': root.find('TimeOfAction').text,
            'first_name': root.find('FirstName').text if root.find('FirstName') is not None else '',
            'last_name': root.find('LastName').text if root.find('LastName') is not None else '',
            'phone': root.find('PhoneNumber').text if root.find('PhoneNumber') is not None else '',
            'email': root.find('EmailAddress').text,
            'business_name': business.find('BusinessName').text if business.find('BusinessName') is not None else '',
            'business_email': business.find('BusinessEmail').text if business.find('BusinessEmail') is not None else '',
            'address': business.find('RealAddress').text if business.find('RealAddress') is not None else '',
            'vat_number': business.find('BTWNumber').text if business.find('BTWNumber') is not None else '',
            'billing_address': business.find('FacturationAddress').text if business.find('FacturationAddress') is not None else ''
        }
    except Exception as e:
        logger.error(f"XML parsing failed: {e}")
        raise

def on_message(channel, method, properties, body):

    try:
        logger.info(f"Received message from {method.routing_key}")
        
        # Parse XML into WordPress-compatible format
        user_data = parse_user_xml(body.decode())
        
        # Only process CREATE actions
        if user_data['action_type'].upper() != 'CREATE':
            logger.warning(f"Ignoring non-CREATE action: {user_data['action_type']}")
            channel.basic_ack(method.delivery_tag)
            return
            
        # Create user in WordPress database
        create_wp_user(user_data)
        
        # Acknowledge message
        channel.basic_ack(method.delivery_tag)
        
    except Exception as e:
        logger.error(f"Message processing failed: {e}")
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
        # Declare all queues we want to listen to
        queues = ['crm_user_create', 'facturatie_user_create', 'kassa_user_create']
        for queue in queues:
            channel.queue_declare(queue=queue, durable=True)
            channel.basic_consume(
                queue=queue,
                on_message_callback=on_message,
                auto_ack=False
            )
        
        logger.info("Waiting for user creation messages...")
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