---
tipo: alteracao
---
## Changelog
- Escrever na pasta recebida de outra conta (fatia 9b de `docs/specs/acesso-restrito-e-compartilhar.md`, mini-adendo da 9b no §4.5.1). Com a permissão "Movimentar", quem recebeu anota na linha do tempo (com prazo no texto, como na pasta) e muda a fase, sem cruzar a fase Contrato e sem finalizar nem reativar; com "Enviar documentos", envia arquivo (só arquivo; contrato e categorias do Financeiro recusados). Tudo grava no escritório dono com o autor de outra conta na trilha, pela mesma conferência da leitura (escritório dono suspenso = só leitura). Menção a pessoas não existe para quem é de outra conta: vira texto simples e não avisa ninguém. Metas seguem só leitura para a outra conta (decisão do Samuel, 18/11). `EnviarMensagemPastaUseCase` ganha o parâmetro `autorDeOutraConta`, sem mudar os chamadores de hoje.
## Para o cliente
- Quem recebe uma pasta de outro escritório agora pode trabalhar nela conforme as permissões marcadas: registrar anotações (com prazo), mudar a fase do andamento e enviar arquivos. Tudo fica na pasta do escritório dono, com o nome de quem fez no histórico. As metas da pasta continuam só para consulta, e a fase de contrato e a conclusão da pasta continuam com o escritório dono.
