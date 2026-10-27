# Login e configuração: identidade dos e-mails (frente `login-config-identidade`)

Risco ALTO: e-mail transacional de autenticação (confirmação de cadastro, redefinição de senha, convites).

## O que muda

As chaves `identidade.nome` e `identidade.remetente` das configurações globais do Master passam a valer nos e-mails do sistema.

- `nome` (padrão `BlueJus`): nome de exibição do From e prefixo do assunto (`[Nome] assunto`).
- `remetente` (endereço): o padrão é o endereço do `MAILER_FROM` do ambiente (hoje `nao-responda@bluejus.com.br`) e só muda se o Master editar. A tela mostra um aviso sobre SPF, DKIM e conta SMTP da Hostinger.
- `suporte` fica fora desta frente.
- O Reply-To continua só do ambiente (`MAILER_REPLY_TO`).

## Regras

1. `IdentidadeDeEmail` lê a configuração a cada chamada (`aplicar`, `remetente`), nunca no construtor, via contrato `ConfiguracaoDaPlataforma`.
2. Nunca lança por causa da configuração: valor vazio, inválido (nome com quebra de linha, aspas, colchetes, mais de 60 caracteres; endereço fora do formato ou com mais de 180) ou leitor que falha cai no valor do ambiente. MAILER_FROM inválido continua falhando no construtor (erro de implantação).
3. Com a configuração vazia ou nos padrões, o From e o assunto saem exatamente como antes.
4. Assunto que já traz `[BlueJus]` recebe o prefixo do nome configurado no lugar, sem empilhar dois.
5. O padrão do ambiente reaproveita `CatalogoDeConfiguracoes::sobreposicoes()` (já usado por `sessao`), agora também com `remetente`. O Salvar só grava a chave quando difere do padrão do ambiente.
6. `AbrirCampanhaDoRelatorioUseCase` deixa de montar o remetente à parte e usa `IdentidadeDeEmail::remetente()`.

## Fora do escopo

Os templates de e-mail com "BlueJus" fixo no corpo (tema do e-mail, rodapé, textos) não mudam neste PR.

## Provas

Unit de `IdentidadeDeEmail` (padrão idêntico ao de hoje, configurado, inválido, leitor que lança, leitura por chamada), do catálogo e do leitor; funcional de um mailer real com a configuração gravada; controller do Master (salvar sem mudar não grava; mudar grava; voltar apaga).

## Registro das decisões da revisão
- O `nome` passa pela mesma regra no Master (recusa aspas, colchetes, `<`, `>`, barra invertida e quebra de linha) e no envio, para não haver falso sucesso na tela.
- O padrão do catálogo (`BlueJus`) vence o nome que vier no `MAILER_FROM`; com o `MAILER_FROM` de hoje a saída é idêntica à anterior.
- `remetente` só é gravado quando difere do ambiente; `nome` grava mesmo igual ao padrão (sem efeito, o padrão é fixo).
- Em worker ou comando de longa duração, a configuração é lida a cada e-mail pelo EntityManager vivo: a mudança do Master só aparece quando o EntityManager é recarregado (reinício do worker), e não há consulta extra a cada e-mail.
