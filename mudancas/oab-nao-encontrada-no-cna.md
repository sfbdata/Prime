---
tipo: novidade
---
## Changelog
- Revisão de OAB: "Verificar de novo (nome no CNA)" com o nome em branco registra a inscrição como não encontrada no CNA (Divergente, sem nome, com a data), em vez de voltar a Não verificada sem registro. A lista mostra o motivo da Divergente (não encontrada no CNA, nome ou situação, formato), tirado do que já está gravado, sem coluna nova.
- Meu Perfil: inscrição Divergente mostra o aviso "Não achamos a OAB nº X/UF no Cadastro Nacional dos Advogados. Confira o número." com o atalho "Corrigir o número", que abre a inscrição no modal. Salvar número ou UF novos roda a validação de novo na hora (ValidadorOab); consulta que falha deixa Pendente.
- Aviso da OAB não encontrada no topo das telas, uma vez por sessão, para a própria pessoa (layout/_globais/aviso_oab.html.twig, AvisoDaOabExtension, ObterAvisoDaOabUseCase), com o link /perfil#corrigir-oab-ID.
- Selo "OAB validada" ao lado do número das inscrições confirmadas no Meu Perfil, com animação curta só na primeira vez depois de cada confirmação (marca no navegador).

## Para o cliente
- Quando o número da sua OAB não aparece no Cadastro Nacional dos Advogados, o sistema avisa no Meu Perfil e, uma vez por sessão, no topo das telas, com um botão para corrigir o número. Ao salvar o número certo, a conferência é feita de novo na hora.
- A OAB conferida ganha o selo verde "OAB validada" ao lado do número no Meu Perfil.
- No Painel do Master, a Revisão de OAB passa a registrar, com a data, a inscrição que não foi encontrada no Cadastro Nacional dos Advogados e mostra o motivo de cada divergência.
