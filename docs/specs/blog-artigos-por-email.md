# Artigos dos blogs por e-mail

Pedido do Samuel (19/11/2026), pelo Coordenador: quando um artigo dos blogs for publicado, os usuários com e-mail
confirmado recebem um e-mail com o título, o resumo e o link do artigo, e um "não receber mais" que grava o
descadastro no sistema. Risco BAIXO (não toca ponto, identidade, papel nem permissão). Sem migration.

## O que já existia e foi reaproveitado

- **Porta única de envio do Comercial** (`CarteiroInterface`): grava `comercial_envio`, põe na fila `comercial` e o
  `EnviarEmailHandler` entrega **dentro da cota da Hostinger** (24 h corridas, 2.400 no total, 10 por minuto; o que
  passa do teto é adiado com `DelayStamp`, nunca descartado). Tipo `lista`: o handler relê o descadastro antes de
  mandar.
- **Montagem do e-mail** (`MontadorDeEmail`): layout do Comercial, rodapé legal, link "Não quero mais receber" e os
  cabeçalhos **List-Unsubscribe** e **List-Unsubscribe-Post (One-Click)** em todo e-mail do tipo lista.
- **Descadastro** (`email_descadastro`, `DescadastroDeEmailInterface`): por remetente (aqui a plataforma, `null`) e
  por assunto; link assinado (HMAC do `kernel.secret`) para a rota pública `/email/descadastro`, sem login; o GET só
  mostra, o POST grava (antivírus que pré-abre links não descadastra ninguém).
- **Público**: `CadastradosRepository` (vínculo ativo, usuário ativo, escritório nem suspenso nem excluído, sem
  repetidos por e-mail).

## O que este PR acrescenta

1. **Gatilho**: no **primeiro** "Publicar agora" do artigo (`PublicarArtigoDoBlogUseCase`, quando `publicadoEm`
   ainda é nulo), a mensagem `EnviarArtigoPorEmail(artigoId)` vai para a fila `comercial` com **15 minutos de
   atraso**, tempo para o builder pôr o site no ar. Republicar (artigo já publicado, com alterações) não reenvia.
   A importação do repositório dos sites não dispara.
2. **Envio** (`EnviarArtigoPorEmailUseCase`, no handler da mensagem):
   - artigo inexistente ou que saiu do ar (despublicado no intervalo): nada sai;
   - público: `CadastradosRepository::comEmailConfirmado()`, os cadastrados acima com `email_a_confirmar_desde`
     nulo (quem ainda não clicou na confirmação do cadastro fica de fora);
   - **idempotente**: pula quem já tem envio da origem `blog_artigo:<id>` em `comercial_envio`
     (`EnvioDeEmailRepository::destinatariosDaOrigem`); se a mensagem rodar de novo, ninguém recebe duas vezes;
   - pula quem saiu da lista `blog_artigos` ou de `todos` da plataforma (o handler do Comercial confere de novo);
   - um e-mail por pessoa pelo Carteiro: tipo `lista`, lista `blog_artigos`, escopo plataforma, assunto
     "Artigo novo no blog: <título>", corpo `email/blog/artigo.html.twig` (título, resumo, botão "Ler o artigo" e, no
     fim, em letra pequena e cinza, "Não quer mais receber os artigos dos blogs? Clique aqui").
   - Link do artigo: `https://bluejus.com.br/blog/<categoria>/<slug>`; artigo só do BlueJus Grátis vai para
     `https://bluejus.com.br/gratis/<categoria>/<slug>`; artigo dos dois blogs vai para o Blog BlueJus.
3. **Página do descadastro** com o assunto `blog_artigos`: título "Não quer mais receber os artigos dos blogs?", o
   e-mail mascarado e o botão "Não receber mais artigos"; depois, "Pronto, você não vai mais receber os artigos dos
   blogs.". **O descadastro vale só para os artigos**: a opção "não quero receber nenhum e-mail" não aparece e o
   servidor ignora `todos` nesse assunto. O One-Click do provedor grava só `blog_artigos`, como nos demais assuntos.

## Cota

A origem `blog_artigo:<id>` **não é isenta**: disputa os 2.400 por 24 h com os demais envios (os transacionais e o
convênio OAB também). Com a base maior que a vaga do dia, a entrega se espalha por mais de um dia, como no aviso do
convênio OAB. A chave `COMERCIAL_CAMPANHAS_LIGADAS` não é usada (não passa pelo expansor de campanhas).

## Base legal (anotada pelo Coordenador no pacote jurídico)

Modelo **opt-out** para quem já tem conta: conteúdo informativo do próprio serviço, legítimo interesse (LGPD art. 7º,
IX), com descadastro em um clique em todo e-mail (art. 18) e o List-Unsubscribe. O rodapé diz por que a pessoa
recebe ("tem cadastro no BlueJus e avisamos quando sai artigo novo nos blogs"). Política de Privacidade e Termos de
Uso devem citar o envio de artigos e como sair. A confirmar pelos advogados (item no `pacote-juridico.md`).

## Limites conhecidos (revisão de 19/11)

- O link usa o slug da versão que está no ar; a categoria é a atual do artigo (não entra na versão congelada). Trocar
  a categoria nos 15 minutos entre o Publicar e o envio deixaria o link errado.
- O envio enfileira todos numa mensagem só. A reentrega é segura (pula quem já tem envio da origem), mas duas cópias
  da mensagem rodando ao mesmo tempo (reentrega por `redeliver_timeout`, 1 h) poderiam duplicar; não há trava.
- Se o despacho da mensagem falhar depois de gravar a publicação, o artigo fica no ar sem o e-mail (sem nova
  tentativa).
- Quem saiu de **todos** os e-mails da plataforma também não recebe os artigos (a mesma regra do Carteiro).

## Fica para depois (fora do escopo mínimo)

- "Descadastrados do Master" lista só `comercial_campanhas` e `todos`; incluir `blog_artigos` lá.
- Chave no Meu Perfil para ligar e desligar os artigos (hoje só pelo link do e-mail).
- Disparar no `no_ar` da publicação (em vez do atraso fixo) e o agendamento (fatia P5, que não existe).
- Imagem do artigo no e-mail (a imagem pública hoje é só do site gerado).

## Testes

- `tests/Blog/Unit/EnviarArtigoPorEmailUseCaseTest`: envio aos cadastrados, idempotência, descadastro, artigo fora
  do ar, link do Grátis.
- `tests/Blog/Unit/PublicarArtigoDoBlogUseCaseTest`: a primeira publicação dispara; republicar não.
- `tests/Comercial/Functional/ArtigosPorEmailConsultasTest`: as duas consultas novas no Postgres.
- `tests/Shared/Functional/DescadastroDeEmailControllerTest::testArtigosDosBlogs`: a página e a gravação só de
  `blog_artigos`.
