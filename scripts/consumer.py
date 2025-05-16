from xml.etree.ElementTree import fromstring

def handle_user_create(xml_string):
    xml = fromstring(xml_string)
    if xml.find("ActionType").text != "CREATE":
        return None

    user = {
        "uuid": xml.findtext("UUID"),
        "time": xml.findtext("TimeOfAction"),
        "password": xml.findtext("EncryptedPassword"),
        "first_name": xml.findtext("FirstName"),
        "last_name": xml.findtext("LastName"),
        "phone": xml.findtext("PhoneNumber"),
        "email": xml.findtext("EmailAddress"),
        "business_name": xml.findtext("Business/BusinessName"),
        "business_email": xml.findtext("Business/BusinessEmail"),
        "real_address": xml.findtext("Business/RealAddress"),
        "btw_number": xml.findtext("Business/BTWNumber"),
        "facturation_address": xml.findtext("Business/FacturationAddress")
    }
    return user


def handle_user_update(xml_string):
    xml = fromstring(xml_string)
    if xml.find("ActionType").text != "UPDATE":
        return None

    user = {
        "uuid": xml.findtext("UUID"),
        "time": xml.findtext("TimeOfAction"),
        "password": xml.findtext("EncryptedPassword"),
        "first_name": xml.findtext("FirstName"),
        "last_name": xml.findtext("LastName"),
        "phone": xml.findtext("PhoneNumber"),
        "email": xml.findtext("EmailAddress"),
        "business_name": xml.findtext("Business/BusinessName"),
        "business_email": xml.findtext("Business/BusinessEmail"),
        "real_address": xml.findtext("Business/RealAddress"),
        "btw_number": xml.findtext("Business/BTWNumber"),
        "facturation_address": xml.findtext("Business/FacturationAddress")
    }
    return user


def handle_user_delete(xml_string):
    xml = fromstring(xml_string)
    if xml.find("ActionType").text != "DELETE":
        return None

    return {
        "uuid": xml.findtext("UUID"),
        "time": xml.findtext("TimeOfAction")
    }
