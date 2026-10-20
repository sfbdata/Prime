---
tipo: interno
---
## Changelog
- Backend do Encaminhar via Chat I.A (desenho 02): `GET /central/encaminhar/destinos` e `POST /central/encaminhar` encaminham um item da pasta (registro, documento, processo, meta, prazo, Push, nota técnica) para um colega ou grupo da Central, só para quem vê a pasta; "Urgente" avisa no sino. A tela vem no próximo PR.
- A mensagem de despacho guarda a referência da pasta (`despacho_pasta_id`, `despacho_tipo`; migration `Version20261020129000`) e a leitura esconde o despacho de quem não vê a pasta ("Item de pasta sem acesso"), inclusive para quem entra no grupo depois.
