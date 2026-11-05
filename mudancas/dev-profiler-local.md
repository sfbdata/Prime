---
tipo: correcao
---
## Changelog
- Auditoria 4.01 (parcial): no `nginx.conf` de dev, `/_profiler` e `/_wdt` (também por `/index.php/_profiler`) só respondem a `127.0.0.1` e ao gateway da rede `saas_net` (`172.18.0.1`, que é como o Docker entrega o acesso de `localhost:8080`). Outro IP recebe 403. A porta 8080 continua aberta na rede.
- Conferência ao vivo com um nginx temporário usando este arquivo: do computador, `/_profiler/` 302 (painel); de outro IP na rede Docker, `/_profiler/`, `/_wdt/x`, `/index.php/_profiler/`, `/%5Fprofiler/` e `//_profiler/` dão 403, e `/login` segue 200.
- Teste: `NginxDevProfilerSoLocalTest`. Produção não muda.

## Para o cliente
- Reforço de segurança no ambiente de desenvolvimento: o painel técnico de diagnóstico só abre no próprio computador do desenvolvedor, e não para outras máquinas da rede.
