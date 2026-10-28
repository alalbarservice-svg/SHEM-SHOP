#!/bin/bash
pgrep dockerd >/dev/null || (nohup dockerd >/tmp/dockerd.log 2>&1 &)
for i in $(seq 1 40); do docker info >/dev/null 2>&1 && break; sleep 2; done
cd /home/claude/staging && docker compose -p shem-staging up -d >/dev/null 2>&1
for i in $(seq 1 40); do curl -s -o /dev/null -w "%{http_code}" localhost:8081/ | grep -q 200 && curl -s -o /dev/null -w "%{http_code}" localhost:8082/ | grep -q 200 && echo UP && exit 0; sleep 3; done
echo NOT_UP
