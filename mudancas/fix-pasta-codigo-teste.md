---
tipo: interno
---
## Changelog
- `PastaShowEmLoteTest`: o código do despacho registrado saía de `++$this->codigo + random_int(0, 8000)`, que repete (1+5 e 2+4) e derrubava o teste de vez em quando na chave única `(tenant_id, codigo)` (run 45410942732, master d24053875). Agora sai só do contador, único dentro do teste.

## Para o cliente
- Corrigimos um teste automático da tela da pasta que às vezes falhava por sorteio. Com isso, as próximas versões saem sem atraso por causa desse alarme falso.
