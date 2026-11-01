import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch
spec = importlib.util.spec_from_file_location('cycling', Path(__file__).resolve().parents[1] / 'import-europe-cycling.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class CyclingTests(unittest.TestCase):
    def test_nested_cycles_and_separate_segments(self):
        way = lambda n: {'type': 'way', 'geometry': [{'lat': n, 'lon': 1}, {'lat': n, 'lon': 2}]}
        a = {'id': 1, 'members': [way(1), {'type': 'relation', 'ref': 2}]}
        b = {'id': 2, 'members': [way(2), {'type': 'relation', 'ref': 1}]}
        self.assertEqual(len(m.segments_for(a, {1: a, 2: b})), 2)
    def test_invalid_and_zero_geometry(self):
        r = {'id': 1, 'members': [{'type': 'way', 'geometry': [{'lat': 91, 'lon': 0}, {'lat': 1, 'lon': 1}]}, {'type': 'way', 'geometry': [{'lat': 1, 'lon': 1}] * 2}]}
        self.assertEqual(m.segments_for(r, {}), [])
    def test_waits_one_direction(self):
        with patch.object(m.subprocess, 'check_output', return_value='123 python3 -u scripts/import-world-walking.py\n'):
            self.assertTrue(m.predecessors_running())
        with patch.object(m.subprocess, 'check_output', return_value='123 bash -c inspect import-world-walking.py status\n'):
            # Shell command text is not mistaken for a live Python import.
            self.assertFalse(m.predecessors_running())
    def test_atomic_resume_state(self):
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / 'status.json'
            m.runner.save(p, {'completed': {'1-2': {'created': 2}}, 'failed': {}})
            state = m.json.loads(p.read_text())
            self.assertIn('1-2', state['completed'])
            self.assertFalse(p.with_suffix('.tmp').exists())

if __name__ == '__main__':
    unittest.main()
