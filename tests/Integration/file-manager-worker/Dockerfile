FROM php:8.3-cli
RUN apt-get update -qq && apt-get install -y --no-install-recommends openssh-server python3 openssl mount && rm -rf /var/lib/apt/lists/*
RUN mkdir -p /run/sshd
COPY file-manager-worker /fixture/helper
COPY tests/Integration/file-manager-worker/start.py /fixture/start.py
CMD ["python3", "/fixture/start.py"]
