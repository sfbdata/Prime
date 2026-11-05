---
tipo: correcao
---
## Changelog
- Sino, chat do Trabalho Externo, PIX do Contribua e esteira de Minhas Metas (auditoria de segurança, 2ª rodada, lote K: 1.15, 2.09, Contribua pix, esteira):
  - 1.15: as leituras do sino (`findNaoLidasByUsuario`, `countNaoLidasByUsuario`, `findPaginadasByUsuario`, `countByUsuario`, `temNotificacaoGestao`) recebem o escritório explícito. Sem escritório na sessão, só o Master continua sem corte; os demais veem apenas os avisos do Trabalho Externo dos escritórios com vínculo ativo ou convite vigente, e a aba Gestão fica fechada (`NotificacaoService::escritorioDoSino`).
  - 2.09: o registro da proposta (com o valor) e os de reabrir, pedir e responder informações, pedir ajustes, recusar, cancelar e aceitar a proposta gravam como `TipoMensagem::Negociacao`, que o Convidado não lê no chat, nas não lidas, no aviso do painel nem nos avisos de andamento. No histórico da demanda, os marcos dessas ações chegam ao Convidado sem o motivo, mesmo depois do aceite (`AcaoDemanda::destinosDaNegociacao`). Solicitante e Central continuam lendo tudo. Mensagens antigas do chat não mudam.
  - Contribua pix: `/contribua/pix` passa a ser POST com o token CSRF da página (GET responde 405); sem o token, 403 e nenhuma cobrança criada no banco.
  - Esteira: a lista de `?etapa=` e as contagens das caixas aplicam o "ver a pasta" (`PastasVisiveisNaEsteira`, em lote): só entra a meta de pasta que a pessoa pode ver ou de que ela cuida.

## Para o cliente
- Na tela de escolher o escritório, o sino não mostra mais avisos de escritórios em que a pessoa foi suspensa nem avisos de pastas trancadas para ela.
- O profissional convidado para um trabalho externo não vê mais, no chat nem no histórico, o valor de uma nova proposta nem os motivos da renegociação entre a Central e o escritório.
- O código PIX da página Contribua só é gerado pelo próprio botão da página, o que evita cobranças criadas por links de outros sites.
- Na esteira de Minhas Metas, cada pessoa vê nas caixas só as metas das pastas que pode abrir, além das que são dela.
