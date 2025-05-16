import unittest
from scripts.consumer import (
    handle_user_create,
    handle_user_update,
    handle_user_delete
)

class TestConsumer(unittest.TestCase):

    def test_handle_user_create_parses_correctly(self):
        xml = """
        <UserMessage>
            <ActionType>CREATE</ActionType>
            <UUID>abc123</UUID>
            <TimeOfAction>2025-05-16T12:00:00Z</TimeOfAction>
            <EncryptedPassword>secret</EncryptedPassword>
            <FirstName>Rayan</FirstName>
            <LastName>Haddou</LastName>
            <PhoneNumber>+32470123456</PhoneNumber>
            <EmailAddress>rayan@example.com</EmailAddress>
            <Business>
                <BusinessName>TestCompany</BusinessName>
                <BusinessEmail>biz@example.com</BusinessEmail>
                <RealAddress>Main Street 1</RealAddress>
                <BTWNumber>BE123456789</BTWNumber>
                <FacturationAddress>Invoice Street 5</FacturationAddress>
            </Business>
        </UserMessage>
        """
        result = handle_user_create(xml)
        self.assertEqual(result["uuid"], "abc123")
        self.assertEqual(result["first_name"], "Rayan")
        self.assertEqual(result["business_name"], "TestCompany")

    def test_handle_user_update_parses_correctly(self):
        xml = """
        <UserMessage>
            <ActionType>UPDATE</ActionType>
            <UUID>xyz789</UUID>
            <TimeOfAction>2025-05-16T15:00:00Z</TimeOfAction>
            <FirstName>Rayan</FirstName>
            <PhoneNumber>+32470123456</PhoneNumber>
        </UserMessage>
        """
        result = handle_user_update(xml)
        self.assertEqual(result["uuid"], "xyz789")
        self.assertEqual(result["phone"], "+32470123456")

    def test_handle_user_delete_parses_correctly(self):
        xml = """
        <UserMessage>
            <ActionType>DELETE</ActionType>
            <UUID>del555</UUID>
            <TimeOfAction>2025-05-16T18:00:00Z</TimeOfAction>
        </UserMessage>
        """
        result = handle_user_delete(xml)
        self.assertEqual(result["uuid"], "del555")
        self.assertEqual(result["time"], "2025-05-16T18:00:00Z")

    def test_handle_user_create_ignores_wrong_action_type(self):
        xml = """
        <UserMessage>
            <ActionType>DELETE</ActionType>
        </UserMessage>
        """
        self.assertIsNone(handle_user_create(xml))

    def test_handle_user_create_without_business(self):
        xml = """
        <UserMessage>
            <ActionType>CREATE</ActionType>
            <UUID>abc</UUID>
            <FirstName>Rayan</FirstName>
        </UserMessage>
        """
        result = handle_user_create(xml)
        self.assertEqual(result["first_name"], "Rayan")
        self.assertIsNone(result["business_name"])

    def test_handle_user_update_missing_fields(self):
        xml = """
        <UserMessage>
            <ActionType>UPDATE</ActionType>
            <UUID>abc</UUID>
        </UserMessage>
        """
        result = handle_user_update(xml)
        self.assertEqual(result["uuid"], "abc")
        self.assertIsNone(result["email"])

if __name__ == "__main__":
    unittest.main()
