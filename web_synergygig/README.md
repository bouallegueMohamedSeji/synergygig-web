# Web SynergyGig — README

Welcome to the Symfony web version of **SynergyGig** — an HR & Workforce Management Platform.

This `web_synergygig` folder contains the Symfony web application implementation, ready to be pushed as the web portion of the SynergyGig project.

## About This Project

This is a complete rewrite of the [SynergyGig JavaFX Desktop Application](https://github.com/bouallegueMohamedSeji/synergygig) as a modern, scalable **web application** built with **Symfony 7.x**.

### From Desktop to Web

| Feature | Desktop (JavaFX) | Web (Symfony) |
|---------|------------------|---------------|
| UI Framework | JavaFX 21 + CSS | Bootstrap 5 + Tailwind + Alpine.js |
| Database | MySQL (JDBC) | MySQL (Doctrine ORM) |
| Real-time | WebSocket (raw PCM) | Socket.io / Swoole WebSocket |
| Deployment | Fat JAR | Docker + Kubernetes |
| Scalability | Single machine | Cloud-native & horizontally scalable |

## Quick Start

### Prerequisites
- PHP 8.3+
- Docker & Docker Compose (recommended)
- MySQL 8.0
- Node.js 18+ (for frontend assets)

### Setup (Development)

```bash
# Clone repository
git clone <repo-url> web_synergygig
cd web_synergygig

# Copy environment file
cp .env.example .env

# Start Docker containers
docker-compose up -d

# Install PHP dependencies
docker-compose exec php composer install

# Generate JWT keys
docker-compose exec php bin/console lexik:jwt:generate-keypair

# Run database migrations
docker-compose exec php bin/console doctrine:migrations:migrate

# Load fixtures (optional)
docker-compose exec php bin/console doctrine:fixtures:load

# Build frontend assets
npm install
npm run dev
```

Visit: **http://localhost:8000**

## Project Structure

```
web_synergygig/
├── src/                    # PHP source code
│   ├── Controller/        # HTTP controllers
│   ├── Entity/            # Doctrine entities
│   ├── Repository/        # Database repositories
│   ├── Service/           # Business logic
│   └── Security/          # Authentication & authorization
├── templates/             # Twig HTML templates
├── assets/                # CSS, JavaScript, Images
├── config/                # Symfony configuration
├── migrations/            # Database migrations
├── tests/                 # Unit & functional tests
├── docker/                # Dockerfile & configs
├── public/                # Web root (index.php)
└── PROJECT_PLAN.md        # Detailed project plan
```

## Architecture

### 10 Major Modules

1. **User Management** — Authentication, profiles, roles, face ID
2. **Chat & Messaging** — Real-time DMs, group rooms, AI assistant
3. **Audio/Video Calls** — Voice calls, screen sharing, live transcription
4. **HR Management** — Leave, attendance, payroll, policies
5. **Project Management** — Projects, tasks (Kanban), code review
6. **Jobs & Contracts** — Job offers, applications, contract generation
7. **Training & Certification** — Courses, enrollments, certificates
8. **Community** — Groups, posts, reactions, friend system
9. **AI Integration** — Multi-provider AI, interview prep, document parsing
10. **Admin Dashboard** — User management, analytics, system config

## Technology Stack

```
Frontend:     Bootstrap 5, Tailwind CSS, Alpine.js
Backend:      Symfony 7.1 LTS, PHP 8.3
ORM:          Doctrine 3.x
Database:     MySQL 8.0
Real-time:    Swoole WebSocket / RabbitMQ
Auth:         JWT + Sessions
Testing:      PHPUnit, Behat
Deployment:   Docker, Kubernetes, Helm
Monitoring:   ELK Stack, Prometheus
```

## Key Files

| File | Description |
|------|-------------|
| `PROJECT_PLAN.md` | Complete 20-week development plan (7 phases) |
| `docker-compose.yml` | Development environment setup |
| `docker/Dockerfile` | Production container build |
| `config/packages/doctrine.yaml` | Database ORM configuration |
| `config/packages/security.yaml` | Authentication & authorization |
| `src/Entity/` | 25 domain entities |
| `src/Service/` | 29 business logic services |
| `templates/` | Twig templates for all pages |

## Development Workflow

### Running Commands

```bash
# Start development server
docker-compose up -d

# Run tests
docker-compose exec php bin/phpunit

# Create new migration
docker-compose exec php bin/console make:migration

# Apply migrations
docker-compose exec php bin/console doctrine:migrations:migrate

# View logs
docker-compose logs -f php

# Access database shell
docker-compose exec db mysql -u root -p synergygig
```

### Code Style

This project follows **PSR-12** coding standards. Use:

```bash
# Check code style
docker-compose exec php vendor/bin/phpcs src/

# Fix code style automatically
docker-compose exec php vendor/bin/phpcbf src/
```

## Testing

```bash
# Run all tests
docker-compose exec php bin/phpunit

# Run specific test class
docker-compose exec php bin/phpunit tests/Unit/Service/ServiceUserTest.php

# Run with coverage
docker-compose exec php bin/phpunit --coverage-html=reports/coverage
```

## Deployment

### Staging

```bash
# Build Docker image
docker build -t synergygig:latest .

# Push to registry
docker tag synergygig:latest <registry>/synergygig:latest
docker push <registry>/synergygig:latest

# Deploy to Kubernetes
kubectl apply -f k8s/deployment.yaml
```

### Production

See [PROJECT_PLAN.md](PROJECT_PLAN.md#phase-6-cicd--deployment-week-16-18) for full CI/CD & deployment strategy.

## Documentation

- **[PROJECT_PLAN.md](PROJECT_PLAN.md)** — Complete development plan (20 weeks, 7 phases)
- **[ARCHITECTURE.md](docs/ARCHITECTURE.md)** — System architecture & design decisions
- **[API.md](docs/API.md)** — REST API documentation (auto-generated from Swagger)
- **[DEPLOYMENT.md](docs/DEPLOYMENT.md)** — Deployment & DevOps guide
- **[CONTRIBUTING.md](CONTRIBUTING.md)** — Contribution guidelines

## API Documentation

Swagger UI: **http://localhost:8000/api/doc**

Endpoints documented with OpenAPI 3.0 format. All REST endpoints include:
- Request/response schemas
- Authentication requirements
- Error codes & messages
- Example requests & responses

## Performance Targets

| Metric | Target | Status |
|--------|--------|--------|
| API Response Time (p95) | < 200ms | Testing |
| Page Load Time | < 2s | Testing |
| Uptime SLA | 99.9% | Not yet deployed |
| Code Coverage | > 80% | In progress |
| Concurrent Users | 10,000+ | Load testing soon |

## Monitoring & Observability

Post-launch monitoring stack includes:

- **ELK Stack** — Application logs & traces
- **Prometheus + Grafana** — Metrics & dashboards
- **Sentry** — Error tracking & alerting
- **Jaeger** — Distributed tracing

Dashboards: **http://grafana.example.com** (production only)

## Troubleshooting

### Common Issues

**Q: Database connection fails**
```
A: Check .env file: DB_HOST should match docker-compose service name (db)
   Run: docker-compose ps
```

**Q: Frontend assets not loading**
```
A: Rebuild assets: npm run dev
   Clear cache: docker-compose exec php bin/console cache:clear
```

**Q: WebSocket connection errors**
```
A: Check firewall allows port 9000 (WebSocket)
   View logs: docker-compose logs websocket
```

## Contributing

1. Create a feature branch: `git checkout -b feature/your-feature`
2. Make changes & write tests
3. Submit pull request with description
4. Code review by maintainers
5. Merge to main after approval

See [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.

## Team & Support

**Project Lead:** Seji (Mohamed Seji Bouallegue)  
**Architectural Advisor:** Antigravity  
**Coaches:** Sana Fayechi · Fakhreddine GHALLEB  
**Institution:** Esprit School of Engineering, Tunisia

Questions? Open an issue on GitHub or contact the team.

## License

Academic Project — Esprit PIDEV 2026. See LICENSE file.

---

**Status:** 🟡 In Development (Phase 0)  
**Estimated Launch:** August 17, 2026  
**Last Updated:** March 30, 2026
