import base64
import json
from pathlib import Path

ORDER = json.loads(base64.b64decode("__ORDER__"))
TESTS = json.loads(base64.b64decode("__TESTS__"))


def process(requests, capacity):
    orders = 0
    history = {}
    status = "pending"
    replayed = False
    for request_id, amount in requests:
        status = "pending"
        replayed = False
        for operation in ORDER:
            if operation == "duplicate" and request_id in history:
                status = history[request_id]
                replayed = True
                break
            if (operation == "validate" and amount <= 0) or (operation == "capacity" and amount > capacity):
                status = "rejected"
                history[request_id] = status
                break
            if operation == "reserve":
                capacity -= amount
                orders += 1
                status = "reserved"
            if operation == "remember":
                history[request_id] = status
    return f"{'replayed' if replayed else status}:{capacity}:{orders}"


def evaluate():
    results = []
    for case in TESTS:
        actual = process(case["requests"], case["capacity"])
        passed = actual == case["expected"]
        results.append({"case": case["key"], "label": case["label"], "expected": case["expected"], "actual": actual, "passed": passed})
        print(f"{'PASS' if passed else 'FAIL'} | {case['label']} | expected={case['expected']} | actual={actual}")
    report = {"passed": all(item["passed"] for item in results), "tests": results,
              "limitations": ["Sequential requests only", "In-memory storage only", "No payment, authentication or concurrency guarantees"],
              "next_step": "Revise ORDER or process(), run again, compare results, then add a boundary test."}
    Path("product-results.json").write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    return report["passed"]


if __name__ == "__main__":
    raise SystemExit(0 if evaluate() else 1)
