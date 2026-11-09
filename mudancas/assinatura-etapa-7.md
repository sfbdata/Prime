---
tipo: novidade
---
## Changelog
- Assinatura de documentos, etapa 7 (docs/specs/assinatura-documentos-etapa-7.md): nível Reforçada. O envio aceita signatário Reforçada (código por e-mail, selfie com documento e localização) e recusa a Reforçada para papel de menor e quando falta a chave da selfie.
- Tela pública: aviso sobre o uso da selfie e da localização, botão "Enviar selfie com documento" (câmera frontal) e a linha da localização, entre o código e o aceite, como no desenho; a prévia do escritório mostra o botão desabilitado. A foto é reduzida no navegador e, no servidor, conferida (JPEG ou PNG pelo conteúdo, até 4 MB, de 240 a 4.096 px), regravada em JPEG (sai o EXIF) e cifrada com XChaCha20-Poly1305 amarrada ao escritório, ao documento e ao signatário.
- Chave nova `ASSINATURA_SELFIE_CHAVE` (64 hexadecimais), separada das outras; a versão dela vai no arquivo e na trilha. Migration `Version20261109270000` (colunas da selfie no signatário); a localização usa as colunas que já existiam.
- Detalhe do documento: "E-mail e código, selfie, localização ..." de quem assinou Reforçada; trilha "... assinou (Reforçada): selfie e localização ...". Serviço que abre a selfie para o manifesto da etapa 3 (sem tela nesta etapa). Runbook: docs/runbooks/assinatura-selfie-chave-em-producao.md.
- Revisão: o espelho no R2 passa a cobrir a rubrica de quem assinou (faltava desde a etapa 2) e a selfie cifrada; o `app:conferir-segredos` confere `ASSINATURA_SEGREDO` e `ASSINATURA_SELFIE_CHAVE` sem mostrar os valores; chave com quebra de linha no fim é recusada; a precisão da localização passa a ser obrigatória; o papel de menor reconhece também "menores", "criança(s)" e "adolescente(s)"; o aviso sobre a selfie diz o que é cifrado e quem vê.

## Para o cliente
- A Assinatura de documentos ganhou a forma Reforçada: além do código no e-mail, quem assina tira uma selfie com documento e informa a localização do celular. A foto fica guardada protegida, só para comprovar a assinatura. Menores de idade continuam assinando pela forma Simples. A tela continua desligada e só aparece para a equipe do BlueJus; avisaremos quando estiver liberada.
