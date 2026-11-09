---
tipo: novidade
---
## Changelog
- Assinatura de documentos, etapa 2 (docs/specs/assinatura-documentos-etapa-2.md): "Gerar links e enviar" congela o documento e grava `hash_enviado` (sha256 do conteúdo canônico); convite por e-mail aos signatários (com ordem, só ao primeiro); detalhe com Copiar link, E-mail (`mailto:`), WhatsApp, prévia só de leitura da tela do signatário e Cancelar envio com confirmação; lembrete ao primeiro pendente pela lista (1 por hora).
- Tela pública `/assinar/{token}` sem login, nível Simples: código de 6 dígitos por e-mail (15 minutos, 5 tentativas, teto diário), assinatura desenhada ou digitada, aceite versionado, recusa com motivo e "Assinatura registrada"; avisos por e-mail ao próximo da fila e ao remetente (recusa e conclusão).
- Token do link derivado por HMAC do segredo `ASSINATURA_SEGREDO` (variável nova; só o sha256 vai ao banco); limitadores por IP, por link e por signatário; cabeçalhos `noindex`, `no-store` e `no-referrer`; filtro de escritório desligado só nas rotas públicas.
- Trilha de auditoria com as evidências de cada passo (IP e porta, navegador, hora do servidor, versão da chave). Sem migration. Runbook: docs/runbooks/assinatura-segredo-em-producao.md.

## Para o cliente
- A Assinatura de documentos ganhou o envio: o escritório manda o link por e-mail ou WhatsApp e o cliente assina pelo celular, sem criar conta, com um código que chega no e-mail dele. A tela continua desligada e só aparece para a equipe do BlueJus; avisaremos quando estiver liberada.
