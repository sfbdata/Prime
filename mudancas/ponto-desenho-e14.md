---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E14 do desenho 16 (VS 08.11.26): fila sem internet na tela /ponto. Sem internet (ou quando o envio falha por rede), a batida fica guardada no aparelho, cifrada (AES-GCM com chave não extraível no IndexedDB, `public/js/ponto-fila.js`), e é enviada sozinha ao carregar a tela, quando a conexão volta e a cada 60 s. Caixa do desenho "Sem internet: N batida(s) aguardando envio." e o aviso "Batidas sem internet enviadas.". Batidas da mesma pessoa guardadas em outro escritório aparecem numa linha própria. A recusa do servidor tira a batida da fila e a guarda, cifrada, até a tela mostrar o tipo e a data. Entradas que não abrem ou com mais de 7 dias são apagadas do aparelho.
- `POST /ponto/batida` aceita a batida da fila (`semInternet`, `usuarioId`, `escritorioId`): vale a hora do aparelho, a hora de chegada fica guardada e a batida leva a marca de enviada sem internet. Recusa a batida guardada há mais de 72 horas ou com o relógio do aparelho mais de 2 minutos adiantado; batida de outra conta ou escritório volta 409 e continua guardada. Todas as regras da batida (home office, cerca, outro escritório, repouso, interjornada, entrada do dia) são avaliadas na hora da batida.
- Folha da /ponto e ficha do gestor: etiqueta "Sem internet" no dia com batida vinda da fila (`FolhaPontoBuilder`, chave `semInternet`). Sem migration (usa as colunas da E13).

## Para o cliente
- Sem internet, o Ponto guarda a batida no seu aparelho e envia sozinho quando a conexão voltar. A tela mostra quantas batidas estão aguardando envio.
- A batida guardada vale com a hora em que você tocou no botão. Ela precisa ser enviada em até 72 horas.
- Se o sistema não aceitar uma batida guardada, a tela avisa qual foi e de que dia.
- Na folha de ponto, o dia com batida enviada depois aparece com a etiqueta "Sem internet", para você e para o gestor.
