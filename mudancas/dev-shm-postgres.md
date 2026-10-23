---
tipo: interno
---
## Changelog
- Ambiente de desenvolvimento: o banco PostgreSQL (jusprime_db_dev) passa a ter 512 MB de memória compartilhada (shm_size), no lugar dos 64 MB do padrão do Docker, que se esgotavam com a suíte de testes em paralelo e derrubavam o banco. Só o compose de desenvolvimento muda; produção fica igual.
