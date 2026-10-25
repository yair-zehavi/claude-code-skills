#!/usr/bin/env python3
"""Minimal Cloudflare API caller. Usage: cf-api.py METHOD /path [json-body]

The token comes from the CF_API_TOKEN environment variable and is never printed.
Examples:
  cf-api.py GET  /zones/<zone_id>
  cf-api.py GET  "/zones/<zone_id>/dns_records?type=A&name=<domain>"
  cf-api.py POST /zones/<zone_id>/dns_records '{"type":"MX","name":"<domain>","content":"<mx-host>","priority":10,"proxied":false,"ttl":1}'
  cf-api.py PATCH /zones/<zone_id>/settings/ssl '{"value":"full"}'
"""
import json
import os
import sys
import urllib.error
import urllib.request


def cf(method, path, body=None):
    token = os.environ.get("CF_API_TOKEN")
    if not token:
        return {"success": False, "errors": [{"message": "CF_API_TOKEN not set"}]}
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request("https://api.cloudflare.com/client/v4" + path, data=data, method=method)
    req.add_header("Authorization", "Bearer " + token)
    req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return json.load(r)
    except urllib.error.HTTPError as err:
        try:
            return json.load(err)
        except Exception:
            return {"success": False, "errors": [{"message": f"HTTP {err.code}"}]}


if __name__ == "__main__":
    body = json.loads(sys.argv[3]) if len(sys.argv) > 3 else None
    print(json.dumps(cf(sys.argv[1], sys.argv[2], body), ensure_ascii=False))
