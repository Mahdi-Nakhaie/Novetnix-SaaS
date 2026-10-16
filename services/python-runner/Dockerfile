ARG PYTHON_BASE
FROM ${PYTHON_BASE}
ENV PYTHONDONTWRITEBYTECODE=1 PIP_DISABLE_PIP_VERSION_CHECK=1
COPY requirements-science.txt /opt/runner/requirements.txt
RUN pip install --no-cache-dir --only-binary=:all: -r /opt/runner/requirements.txt
COPY bootstrap.py policy.py /opt/runner/
USER 65532:65532
WORKDIR /workspace
