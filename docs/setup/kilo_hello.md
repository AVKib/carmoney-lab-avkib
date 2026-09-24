готов
1) Учебный сервис предварительной оценки заявки на заём под ПТС: принимает заявку, считает LTV и возвращает approve / review / reject.
2) Makefile: make help, make up, make down, make ps, make logs, make install, make test, make lint, make seed; docker-compose.yml: backend запускает `php -S 0.0.0.0:8080 -t backend/public backend/public/router.php`, db — MySQL 8.0.
3) Решение approve / review / reject по заявке считается в папке backend/src/Domain/ (файл DecisionEngine.php).
модель: training-2026-09-minimax-m3