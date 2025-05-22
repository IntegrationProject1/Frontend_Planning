import unittest
from unittest.mock import MagicMock
from scripts.producer import send_user_to_rabbitmq, generate_user_xml

class TestProducer(unittest.TestCase):

    def setUp(self):
        self.mock_channel = MagicMock()
        self.user = {
            "uuid": "2025-05-16T12:00:00.000000Z",
            "time": "2025-05-16T12:00:00Z",
            "password": "hashed",
            "first_name": "Rayan",
            "last_name": "Haddou",
            "phone": "+32470123456",
            "email": "rayan@example.com",
            "business_name": "MyCompany",
            "business_email": "biz@example.com",
            "real_address": "Main St 1",
            "btw_number": "BE123456789",
            "facturation_address": "Invoice St 5"
        }

    def test_send_user_to_rabbitmq_calls_basic_publish_three_times(self):
        send_user_to_rabbitmq(self.user, self.mock_channel)

        self.assertEqual(self.mock_channel.basic_publish.call_count, 3)
        self.mock_channel.queue_declare.assert_any_call("crm_user_create")
        self.mock_channel.queue_bind.assert_any_call("crm_user_create", "user", "crm.user.create")

    def test_send_user_to_rabbitmq_uses_correct_routing_keys(self):
        send_user_to_rabbitmq(self.user, self.mock_channel)
        keys_used = [call.kwargs["routing_key"] for call in self.mock_channel.basic_publish.call_args_list]
        self.assertIn("crm.user.create", keys_used)
        self.assertIn("facturatie.user.create", keys_used)
        self.assertIn("kassa.user.create", keys_used)

    def test_send_user_to_rabbitmq_uses_text_xml_content_type(self):
        send_user_to_rabbitmq(self.user, self.mock_channel)
        for call in self.mock_channel.basic_publish.call_args_list:
            props = call.kwargs["properties"]
            self.assertEqual(props.content_type, "text/xml")

    def test_generate_user_xml_contains_expected_fields(self):
        xml = generate_user_xml(self.user)
        self.assertIn("<FirstName>Rayan</FirstName>", xml)
        self.assertIn("<BusinessName>MyCompany</BusinessName>", xml)
        self.assertIn("<UUID>2025-05-16T12:00:00.000000Z</UUID>", xml)

if __name__ == "__main__":
    unittest.main()
