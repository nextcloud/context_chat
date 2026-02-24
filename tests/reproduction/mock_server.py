from flask import Flask, request, jsonify
import sys

app = Flask(__name__)

@app.route('/heartbeat', methods=['GET'])
def heartbeat():
    return jsonify({"status": "ok"}), 200

@app.route('/loadSources', methods=['PUT'])
def load_sources():
    content_length = request.headers.get('Content-Length')
    if content_length:
        content_length = int(content_length)

    body = request.get_data()
    actual_length = len(body)

    print(f"Header Content-Length: {content_length}")
    print(f"Actual Body Length: {actual_length}")

    if content_length is not None and content_length != actual_length:
        print("FAIL: Size Mismatch")
        return jsonify({"error": "Size Mismatch"}), 400

    print("SUCCESS")
    return jsonify({
        "loaded_sources": ["test_source"],
        "sources_to_retry": []
    }), 200

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=23000)
