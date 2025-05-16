def generate_user_xml(user):
    xml = "<User>"
    xml += f"<user_login>{user.get('user_login', '')}</user_login>"
    xml += f"<user_pass>{user.get('user_pass', '')}</user_pass>"
    xml += f"<user_email>{user.get('user_email', '')}</user_email>"
    xml += f"<user_registered>{user.get('user_registered', '')}</user_registered>"

    xml += f"<first_name>{user.get('first_name', '')}</first_name>"
    xml += f"<last_name>{user.get('last_name', '')}</last_name>"
    xml += f"<phone_number>{user.get('phone_number', '')}</phone_number>"

    xml += f"<business_name>{user.get('business_name', '')}</business_name>"
    xml += f"<business_email>{user.get('business_email', '')}</business_email>"
    xml += f"<real_address>{user.get('real_address', '')}</real_address>"
    xml += f"<btw_number>{user.get('btw_number', '')}</btw_number>"
    xml += f"<facturation_address>{user.get('facturation_address', '')}</facturation_address>"

    xml += f"<action_type>{user.get('action_type', '')}</action_type>"
    xml += f"<time_of_action>{user.get('time_of_action', '')}</time_of_action>"
    xml += "</User>"
    return xml
