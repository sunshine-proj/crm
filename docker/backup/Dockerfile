FROM alpine:3.21

RUN apk add --no-cache bash postgresql17-client age rclone tar coreutils tzdata

COPY backup.sh restore.sh run-schedule.sh /usr/local/bin/
RUN chmod 0755 /usr/local/bin/backup.sh /usr/local/bin/restore.sh /usr/local/bin/run-schedule.sh

ENTRYPOINT ["/usr/local/bin/run-schedule.sh"]
