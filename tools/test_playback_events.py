import json
import pathlib
import subprocess
import unittest


ROOT = pathlib.Path(__file__).resolve().parent.parent
ADAPTER = (ROOT / "site/assets/playback-events.js").read_text(encoding="utf-8")
VIEWS = (ROOT / "src/views.php").read_text(encoding="utf-8")
APP = (ROOT / "src/app.php").read_text(encoding="utf-8")


def function_body(source, name):
    start = source.index("function " + name + "(")
    end = source.find("\nfunction ", start + 1)
    return source[start:end if end != -1 else None]


class PlaybackEventsContractTest(unittest.TestCase):
    def test_adapter_exposes_real_player_contract_without_fake_events(self):
        self.assertIn("window.NoqtePlaybackEvents", ADAPTER)
        self.assertIn("player.addEventListener(type, handlers[type])", ADAPTER)
        self.assertIn("player.removeEventListener(type, binding.handlers[type])", ADAPTER)
        self.assertNotIn("dispatchEvent", ADAPTER)
        self.assertNotIn("setTimeout", ADAPTER)

    def test_normalized_events_include_supported_native_events_and_state(self):
        for event_type in ("play", "pause", "seeking", "seeked", "loadedmetadata", "timeupdate", "ended"):
            self.assertIn('"%s"' % event_type, ADAPTER)
        self.assertIn("currentTime: mediaValue(player.currentTime)", ADAPTER)
        self.assertIn("duration: mediaValue(player.duration)", ADAPTER)
        self.assertIn("return {", ADAPTER)
        self.assertIn("type: type", ADAPTER)

    def test_state_reader_validates_real_timing_without_computing_percentage(self):
        reader = function_body(ADAPTER, "readState")
        self.assertIn("function readState(player)", reader)
        self.assertIn("if (currentTime === null || duration === null", reader)
        self.assertIn("duration <= 0", reader)
        self.assertIn("currentTime > duration", reader)
        self.assertIn("return { currentTime: currentTime, duration: duration }", reader)
        self.assertIn("readState: readState", ADAPTER)

    def test_player_state_contract_uses_real_events_and_retry_api(self):
        for event_type in ("loadstart", "waiting", "stalled", "loadedmetadata", "canplay", "playing", "error"):
            self.assertIn(event_type + ":", ADAPTER)
        for state in ("loading", "ready", "error"):
            self.assertIn('"%s"' % state, ADAPTER)
        self.assertIn("window.NoqtePlaybackEvents = Object.freeze({ attach: attach, detach: detach, retry: retry, readState: readState, connectProgress: connectProgress })", ADAPTER)
        self.assertIn("player.load();", ADAPTER)
        self.assertNotIn("setTimeout", ADAPTER)
        self.assertNotIn("dispatchEvent", ADAPTER)

    def test_progress_connection_reuses_endpoint_and_controls_requests(self):
        self.assertIn("function connectProgress(player, options)", ADAPTER)
        self.assertIn('endpoint = options.endpoint || "/progress/save"', ADAPTER)
        self.assertIn('event.type === "timeupdate"', ADAPTER)
        self.assertIn('event.type === "pause" || event.type === "ended"', ADAPTER)
        self.assertIn("window.setInterval", ADAPTER)
        self.assertIn("inFlight", ADAPTER)
        self.assertIn("pending", ADAPTER)
        self.assertIn("window.fetch", ADAPTER)
        self.assertIn("percentage: String(percentage)", ADAPTER)
        self.assertIn("calculatePercentage(state.currentTime, state.duration)", ADAPTER)
        self.assertIn("window.clearInterval(timer)", ADAPTER)

        self.assertIn("var attached = new WeakMap()", ADAPTER)
        self.assertIn("detach(player);", ADAPTER)
        self.assertIn("return function () { detach(player); }", ADAPTER)

    def test_percentage_calculation_cases(self):
        script = r'''
const fs = require("node:fs");
const vm = require("node:vm");
const source = fs.readFileSync(process.argv[1], "utf8").replace(
  "})(window);", "window.testPercentage = calculatePercentage; })(window);"
);
const context = {window: {}};
vm.runInNewContext(source, context);
const calculate = context.window.testPercentage;
const cases = [
  [0, 1800], [300, 1800], [900, 1800], [1350, 1800], [1800, 1800],
  [1850, 1800], [300, 0], [300, -10], [300, null], [300, Infinity],
  [NaN, 1800], [-5, 1800], [300, 1800], [1200, 1800]
];
process.stdout.write(JSON.stringify(cases.map(([position, duration]) => calculate(position, duration))));
'''
        result = subprocess.run(
            ["node", "-e", script, str(ROOT / "site/assets/playback-events.js")],
            capture_output=True, text=True, check=True,
        )
        self.assertEqual(
            json.loads(result.stdout),
            [0, 16.67, 50, 75, 100, 100, None, None, None, None, None, None, 16.67, 66.67],
        )

    def test_backend_rejects_invalid_percentage_independently(self):
        save = function_body(APP, "save_lesson_progress")
        self.assertIn("!is_numeric($percentage)", save)
        self.assertIn("!is_finite($percentage)", save)
        self.assertIn("$percentage<0 || $percentage>100", save)
        self.assertIn("percentage=excluded.percentage", save)
        self.assertIn("percentage=VALUES(percentage)", save)

    def test_workspace_has_no_real_player_or_aparat_integration_to_verify(self):
        workspace = function_body(VIEWS, "page_course_workspace")
        self.assertIn("video-placeholder", workspace)
        self.assertNotIn("NoqtePlaybackEvents.attach", workspace)
        self.assertNotIn("Aparat", workspace)


if __name__ == "__main__":
    unittest.main()
