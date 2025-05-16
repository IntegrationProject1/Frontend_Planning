from scripts.xml_utils import generate_user_xml

def test_generate_user_xml_contains_all_fields():
    user = {
        "user_login": "weiam123",
        "user_pass": "hashedpassword",
        "user_email": "weiam@example.com",
        "user_registered": "2025-05-16T12:00:00",
        "first_name": "Weiam",
        "last_name": "Almahnash",
        "phone_number": "+32470123456",
        "business_name": "MyCompany",
        "business_email": "business@example.com",
        "real_address": "Main Street 1",
        "btw_number": "BE123456789",
        "facturation_address": "Invoice Street 5",
        "action_type": "create",
        "time_of_action": "2025-05-16T12:00:00"
    }

    xml_output = generate_user_xml(user)

    assert "<user_login>weiam123</user_login>" in xml_output
    assert "<user_email>weiam@example.com</user_email>" in xml_output
    assert "<btw_number>BE123456789</btw_number>" in xml_output
    assert "<action_type>create</action_type>" in xml_output
