FROM postgis/postgis:16-3.4

# Install h3-pg extension from source
RUN apt-get update && apt-get install -y \
    postgresql-server-dev-16 \
    cmake \
    build-essential \
    git \
  && git clone https://github.com/zachasme/h3-pg.git /tmp/h3-pg \
  && cd /tmp/h3-pg \
  && cmake -B build -DCMAKE_BUILD_TYPE=Release \
  && cmake --build build \
  && cmake --install build --component h3-pg \
  && rm -rf /tmp/h3-pg \
  && apt-get purge -y cmake build-essential git \
  && apt-get autoremove -y \
  && rm -rf /var/lib/apt/lists/*
