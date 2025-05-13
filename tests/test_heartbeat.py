import unittest
import sys
import os

sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), '..')))

from heartbeat.heartbeat import get_heartbeat_message

class TestHeartbeatMessage(unittest.TestCase):

    def test_xml_contains_servicename(self):
        xml = get_heartbeat_message()
        self.assertIn("<ServiceName>frontend</ServiceName>", xml)

if __name__ == "__main__":
    unittest.main()
