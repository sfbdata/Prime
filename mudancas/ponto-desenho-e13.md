---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E13 do desenho 16 (VS 08.11.26): a batida guarda a hora do aparelho (só como informação), a hora de chegada ao servidor e um identificador único do envio. Migration `Version20261109220000` em `registro_ponto`: `hora_aparelho`, `hora_chegada`, `enviada_sem_internet` e `id_envio`, com índice único por pessoa e envio.
- `POST /ponto/batida` aceita `idEnvio` (UUID v4 do toque) e `horaAparelho` (milissegundos), ambos opcionais. O mesmo envio da mesma pessoa não cria duas batidas, nem com dois pedidos ao mesmo tempo: responde "Esta batida já tinha sido registrada.". A leitura dos campos mora em `EnvioDaBatida`.
- A tela do Ponto e o atalho do cabeçalho passam a mandar os dois campos. A hora que vale continua sendo a do servidor.

## Para o cliente
- Quando a internet oscila e a batida é enviada de novo, o sistema reconhece que é a mesma e não registra duas vezes.
- A hora da batida continua sendo a hora oficial do sistema. A hora do seu aparelho fica guardada só como informação.
