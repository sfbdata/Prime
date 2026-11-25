---
tipo: novidade
---
## Changelog
- Voto: a assembleia ganha o edital de convocação em PDF. No menu de três pontos do cartão da assembleia (Gestão eleitoral) entram "Anexar edital", "Baixar edital", "Trocar edital" e "Remover edital" (esta com confirmação na janela do Voto). Aceita só PDF de verdade (tipo conferido pelo conteúdo e legível), até 10 MB. Anexar, trocar e remover valem só enquanto a votação não começou; com alguma pauta aberta ou encerrada, ou com a votação arquivada, o edital fica fixo e só se baixa.
- Nova coluna em `voto.assembleia` (migration `Version20261125160000`): `edital_chave`, `edital_nome`, `edital_tamanho`, `edital_sha256`, `edital_enviado_em`. Nova categoria de arquivo `VOTO_EDITAL` (subpasta `voto-editais`, escopo do escritório, entra na purga do escritório e na conferência do espelho remoto).
- O PDF do resumo do resultado (`/voto/assembleias/{id}/relatorio/resumo.pdf`), quando há edital, traz a linha "Edital anexo: nome, SHA-256 hash" e as páginas do edital ao fim, juntadas pelo Ghostscript. Se a junção falhar, o PDF sai sem as páginas do edital (com a linha do hash) e o erro vai para o log.
- Novo `JuntadorDoEditalAoPdf`, `AnexarEditalDaAssembleiaUseCase`, `RemoverEditalDaAssembleiaUseCase`, `EditalDaAssembleiaController` e `public/js/voto-edital.js`. Excluir rascunho ou simulação também apaga o edital, depois do COMMIT.

## Para o cliente
- Agora dá para anexar o edital da assembleia (um PDF de convocação) direto na aba Voto, pelo menu de três pontos da assembleia, sem mudar o visual da tela.
- Esse edital sai junto com o PDF do resultado: as páginas dele ficam no fim do arquivo impresso, e a folha do resultado mostra o nome do edital e o código de conferência dele.
- Você pode trocar ou remover o edital até a votação começar. Depois disso ele fica travado, e só dá para baixar.
