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

if __name__ == "__main__":
    unittest.main()
