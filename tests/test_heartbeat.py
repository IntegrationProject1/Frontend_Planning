import unittest
import sys
import os

sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))

from heartbeat.heartbeat import get_heartbeat_message, dict_to_xml  # ✅ nieuwe import

class TestHeartbeatMessage(unittest.TestCase):

    def test_xml_contains_servicename(self):
        xml = get_heartbeat_message()
        self.assertIn("<ServiceName>frontend</ServiceName>", xml)


class TestXMLGeneration(unittest.TestCase):

    def test_dict_to_xml_contains_servicename(self):
        xml = dict_to_xml({'ServiceName': 'test-service'})
        self.assertIn('<ServiceName>test-service</ServiceName>', xml)

from unittest.mock import patch, MagicMock
from heartbeat.heartbeat import setup_rabbitmq_channel

class TestRabbitMQSetup(unittest.TestCase):

    @patch("heartbeat.heartbeat.pika.BlockingConnection")
    def test_setup_rabbitmq_channel_returns_channel(self, mock_connection):
        mock_channel = MagicMock()
        mock_connection.return_value.channel.return_value = mock_channel

        conn, chan = setup_rabbitmq_channel()

        self.assertTrue(mock_connection.called)
        self.assertEqual(chan, mock_channel)


if __name__ == "__main__":
    unittest.main()
