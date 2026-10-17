ARG PYTHON_BASE
FROM ${PYTHON_BASE}
ENV PYTHONDONTWRITEBYTECODE=1 PIP_DISABLE_PIP_VERSION_CHECK=1
COPY requirements-science.txt /opt/runner/requirements.txt
RUN pip install --no-cache-dir --only-binary=:all: -r /opt/runner/requirements.txt
COPY bootstrap.py policy.py /opt/runner/
COPY curriculum/grading.py /opt/runner/curriculum/grading.py
COPY curriculum/catalog.json /opt/runner/curriculum/catalog.json
COPY curriculum/data /opt/runner/curriculum/data
COPY tools/fetch_curriculum_data.py tools/build_curriculum.py /opt/runner/tools/
USER 65532:65532
WORKDIR /workspace
