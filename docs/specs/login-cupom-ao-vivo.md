# Login: conferência ao vivo do cupom (desenho 10 VS 25.10.26)

Risco MÉDIO (endpoint público que responde se um código existe). Segue a spec `login-cupom-e-facebook.md` (D5) e a
decisão do dono repassada pelo coordenador em 26/10: **fazer**, mostrando só "Cupom válido", **sem o nome de quem indicou**,
com limite de 20 consultas por 15 minutos por IP, configurado em `indicacao_cupom.yaml`.

## Comportamento

1. No campo do cupom do "Criar conta", quando o texto tem o formato de cupom (`CodigoDeIndicacao::doCupom` no servidor;
   `BJ-` + 4 a 12 caracteres `A-Z0-9` no JS), o JS consulta o servidor depois de 400 ms parado (debounce), uma consulta
   por valor, ignorando respostas de valores já trocados.
2. Resposta do servidor: `{"valido": true|false}` e nada mais (sem nome, sem e-mail, sem id). Textos do desenho, abaixo
   do campo: válido = "Cupom válido" (o desenho diz "Cupom válido: indicado por <nome>"; o nome fica de fora por decisão
   do dono); inexistente = "Cupom não encontrado. Confira com quem te indicou." (texto do desenho). Cores e tipografia
   saem do CSS do desenho (`docs/design/redesign-25-10-26/10 - Login - VS 25.10.26.dc.html`).
3. Limite excedido (HTTP 429) ou falha de rede: nenhum texto, nenhum erro; o cadastro segue e o cupom é conferido ao
   enviar como no PR A (cupom inválido nunca derruba o cadastro).
4. Campo vazio, malformado ou chave desligada: nenhum texto, nenhuma consulta. Desligar a chave limpa o texto.
5. Endpoint `POST /cadastro/cupom/conferir` (`auth_cadastro_cupom_conferir`, JSON `{"codigo": "..."}`), público, só
   POST, sem estado (não grava na sessão, não atribui nada). Limiter `indicacao_cupom` em
   `config/packages/indicacao_cupom.yaml` (sliding_window, 20 por 15 minutos, chave = IP do cliente), consumido a cada
   consulta. Resposta com `Cache-Control: no-store`. Código malformado responde `{"valido": false}` sem consultar o banco
   (já consome o limite). Dono inativo ou indicações pausadas: o mesmo critério do `/i/{codigo}` (o que o
   `ConferirCodigoDeIndicacaoUseCase` considera código existente).
6. Aceito e registrado: este endpoint **revela se um código existe** (o `/i/{codigo}` foi feito para não revelar). A
   mitigação é o limite por IP, o formato de 8 caracteres do alfabeto (cerca de 10^12 combinações) e a resposta mínima.
7. Rotas nas listas brancas de usuário anônimo (as mesmas do `/cadastro/google/criar`: `TermoAceiteListener`,
   `TenantContextValidatorListener`, `EntradaDoConvidadoListener`) e no `access_control` se for preciso.

## Testes

- Funcional: código existente `{"valido":true}`; inexistente `{"valido":false}`; malformado/vazio/tipo errado
  (`{"codigo":[]}`) `{"valido":false}` sem erro 500; corpo que não é JSON responde 400 ou `{"valido":false}`; a resposta
  não contém nome nem e-mail do dono; o 21º pedido em 15 min responde 429; `Cache-Control: no-store`; GET responde 405;
  logado também pode consultar; código com e sem `BJ-`.
- Tela: o texto de status existe vazio e escondido no passo `cad`, logo depois do campo do cupom (filho direto do passo).
- JS: `node --check`. Debounce e corrida de respostas conferidos no smoke do dono.
