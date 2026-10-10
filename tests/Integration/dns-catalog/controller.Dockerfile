# Ansible controller reaching the test containers through the Docker socket.
FROM python:3.12-slim
RUN pip install --quiet --no-cache-dir ansible-core==2.17.* && ansible-galaxy collection install --upgrade community.docker >/dev/null
