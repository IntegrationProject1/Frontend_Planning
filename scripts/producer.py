from pika import BasicProperties
from xml.etree.ElementTree import Element, SubElement, tostring

def generate_user_xml(user):
    root = Element("UserMessage")
    SubElement(root, "ActionType").text = "CREATE"
    SubElement(root, "UUID").text = user["uuid"]
    SubElement(root, "TimeOfAction").text = user["time"]
    SubElement(root, "EncryptedPassword").text = user["password"]
    SubElement(root, "FirstName").text = user["first_name"]
    SubElement(root, "LastName").text = user["last_name"]
    SubElement(root, "PhoneNumber").text = user["phone"]
    SubElement(root, "EmailAddress").text = user["email"]

    business = SubElement(root, "Business")
    SubElement(business, "BusinessName").text = user["business_name"]
    SubElement(business, "BusinessEmail").text = user["business_email"]
    SubElement(business, "RealAddress").text = user["real_address"]
    SubElement(business, "BTWNumber").text = user["btw_number"]
    SubElement(business, "FacturationAddress").text = user["facturation_address"]

    return tostring(root, encoding='unicode')

def send_user_to_rabbitmq(user, channel, exchange="user"):
    xml = generate_user_xml(user)
    props = BasicProperties(content_type="text/xml")
    
    routes = {
        "crm_user_create": "crm.user.create",
        "facturatie_user_create": "facturatie.user.create",
        "kassa_user_create": "kassa.user.create"
    }

    for queue, routing_key in routes.items():
        channel.queue_declare(queue)
        channel.queue_bind(queue, exchange, routing_key)
        channel.basic_publish(exchange=exchange, routing_key=routing_key, body=xml.encode(), properties=props)
