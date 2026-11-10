# Runbook: gravar o e-CNPJ do selo da Assinatura em produção

O PDF final de cada documento assinado ganha um selo PAdES do BlueJus, assinado com o e-CNPJ A1 da empresa (spec
`docs/specs/assinatura-documentos-etapa-8.md`, decisões E8-1 a E8-4). Quem sela é o contêiner `validador_pdf`. A chave
(o arquivo PKCS#12, `.pfx` ou `.p12`) e a senha dela ficam fora do git, em `/opt/jusprime/secrets/`, e são montadas só
no validador (`/run/secrets/selo_pkcs12` e `/run/secrets/selo_pkcs12_senha`). O php e o worker nunca veem a chave.

Não confunda com o `ASSINATURA_SELO_SEGREDO` (`assinatura-selo-segredo-em-producao.md`): aquele é o segredo do código
de validação `BJA-...` impresso no PDF; este é o certificado digital da empresa.

**Quando**: depois da compra do e-CNPJ A1 (decisão D3, depois do piloto) e com o validador já ligado
(`docs/runbooks/assinatura-validador-em-producao.md`, que deixa os dois arquivos criados e vazios). Quem faz: o Samuel,
na VPS, na pasta `/opt/jusprime`.

Este arquivo **não contém segredo nenhum**. O e-CNPJ e a senha ficam só na VPS e no cofre de senhas.

## O que acontece sem o e-CNPJ (o estado de hoje)

Com os dois arquivos vazios, o validador responde `503 selo_nao_configurado` e o sistema guarda o PDF final **sem selo**,
sem gravar nada na trilha do documento (E8-4). O documento continua válido pelo código de validação, pelo hash e pela
trilha; só não leva a assinatura digital da empresa.

Cuidados que valem sempre, com o profile `assinatura` ligado:

- **Nunca apague os dois arquivos.** Sem eles o contêiner do validador nem é criado e todo deploy falha e volta. Para
  desligar o selo, esvazie (passo 5).
- O validador roda como o usuário 10001: os arquivos têm de ser dele (`chown 10001:10001`, `chmod 400`). Arquivo que
  existe mas ele não consegue ler (sem permissão, como `root` com `0600`, ou um diretório no lugar do arquivo) **não**
  conta como "sem e-CNPJ": o validador responde `503 selo_certificado_invalido`, o sistema tenta de novo e, esgotadas as
  tentativas, guarda o PDF sem selo e registra `selo_nao_aplicado` na trilha do documento. Só o PKCS#12 vazio é "sem
  e-CNPJ" (`selo_nao_configurado`). Por isso o passo 3 confere.

## 1. Levar o certificado para a VPS

No seu computador (troque o caminho pelo do arquivo `.pfx` ou `.p12` recebido da certificadora):

```bash
# Execute manualmente no terminal externo (no seu computador)
scp /caminho/do/ecnpj.pfx bluejus:/root/selo_pkcs12.novo
```

Na VPS, ponha o arquivo no lugar com o dono e a permissão certos, e apague a cópia de passagem:

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime/secrets && install -m 400 -o 10001 -g 10001 /root/selo_pkcs12.novo selo_pkcs12 && rm -f /root/selo_pkcs12.novo
```

## 2. Gravar a senha sem que ela apareça

O comando pede a senha sem mostrá-la na tela e sem deixá-la no histórico do terminal:

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime/secrets && read -rsp 'Senha do e-CNPJ: ' SENHA && echo && (umask 077 && printf '%s' "$SENHA" > selo_pkcs12_senha.novo) && unset SENHA && install -m 400 -o 10001 -g 10001 selo_pkcs12_senha.novo selo_pkcs12_senha && rm -f selo_pkcs12_senha.novo
ls -ln selo_pkcs12 selo_pkcs12_senha
```

**Esperado:** duas linhas começando com `-r-------- 1 10001 10001`, com o tamanho (quinta coluna) maior que zero.

Guarde o `.pfx` e a senha no cofre de senhas (item "BlueJus: e-CNPJ do selo da assinatura").

Recrie o validador. O arquivo novo é outro arquivo no disco, e o contêiner só passa a enxergá-lo depois de recriado:

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-deps --force-recreate validador_pdf
```

## 3. Conferir sem expor a chave

Espere uns 30 segundos e rode, um por vez.

**a) O validador lê os dois arquivos.** Mostra só se dá para ler e o tamanho:

```bash
# Execute manualmente no terminal externo (na VPS)
docker exec jusprime_validador_pdf_prod python -c "import os; [print(c, os.access(c, os.R_OK), os.path.getsize(c)) for c in ('/run/secrets/selo_pkcs12', '/run/secrets/selo_pkcs12_senha')]"
```

**Esperado:** `True` e um tamanho maior que zero nas duas linhas. `False`: o dono ou a permissão estão errados (refaça o
passo 1 ou 2 com o `install` deles). Tamanho `0`: o contêiner não foi recriado (rode o último comando do passo 2).

**b) A senha abre o certificado.** Mostra só o titular e a validade, nunca a chave nem a senha:

```bash
# Execute manualmente no terminal externo (na VPS)
docker exec jusprime_validador_pdf_prod python -c "
from cryptography.hazmat.primitives.serialization import pkcs12
dados = open('/run/secrets/selo_pkcs12', 'rb').read()
senha = open('/run/secrets/selo_pkcs12_senha', 'rb').read().rstrip(b'\r\n')
chave, cert, extras = pkcs12.load_key_and_certificates(dados, senha or None)
print('senha confere; chave presente:', chave is not None)
print('titular:', cert.subject.rfc4514_string())
print('valido ate:', cert.not_valid_after_utc)
"
```

**Esperado:** `senha confere; chave presente: True`, o nome e o CNPJ da empresa no titular e uma data de validade no
futuro. `ValueError: Invalid password or PKCS12 data`: senha errada (refaça o passo 2) ou arquivo errado (refaça o
passo 1).

**c) O validador aceita o selo.** De dentro do php, como o sistema chama, com o corpo vazio de propósito:

```bash
# Execute manualmente no terminal externo (na VPS)
docker exec jusprime_php_prod sh -c 'printf "Authorization: Bearer %s\n" "$ASSINATURA_VALIDADOR_TOKEN" | curl -sS -H @- -H "Content-Type: application/pdf" --data-binary "" -w "\n%{http_code}\n" "$ASSINATURA_VALIDADOR_URL/v1/selar"'
```

| O que aparece | O que quer dizer |
|---|---|
| `{"v":1,"erro":"pdf_ilegivel"}` e `400` | Certo: os dois arquivos chegaram, o validador consegue lê-los e o PKCS#12 não está vazio (o corpo vazio é recusado de propósito) |
| `selo_nao_configurado` e `503` | O PKCS#12 está vazio, ou o contêiner não foi recriado depois do passo 2 (ele segue vendo o arquivo vazio antigo). Volte ao item a |
| `selo_certificado_invalido` e `503` | Os arquivos existem, mas o validador não consegue usá-los: sem permissão de leitura para o usuário 10001 (por exemplo `root` com `0600`), um diretório no lugar do arquivo ou outro erro de leitura (volte ao item a); ou o arquivo passou de 1 MB, ou a senha passou de 4 KB (arquivo errado: refaça o passo 1 ou 2) |
| `nao_autorizado` e `401` | Token diferente entre o php e o validador: veja o runbook do validador, passo 5 |

**d) Um documento de verdade.** O item c não confere a cadeia do e-CNPJ. Conclua um documento de teste (assinatura
Simples basta) e veja o log do validador:

```bash
# Execute manualmente no terminal externo (na VPS)
docker logs --since 30m jusprime_validador_pdf_prod 2>&1 | grep -E 'selar|selo' | tail -5
```

**Esperado:** uma linha `POST /v1/selar 200 ... resultado=selado saida=...`. Se aparecer `selo com certificado
invalido`, o e-CNPJ está vencido, revogado, sem o uso de chave de assinatura ou fora das raízes ICP-Brasil (ou a
senha está errada): o PDF sai sem selo depois das tentativas e a trilha do documento registra `selo_nao_aplicado`.
Confira o item b e, se ele estiver certo, fale com o desenvolvimento. Se aparecer `ancoras_indisponiveis`, as raízes da
ICP-Brasil ainda não carregaram: runbook do validador, passo 6.

## 4. Carimbo do tempo (opcional, quando houver ACT contratada)

Sem ACT, o selo sai sem carimbo do tempo (PAdES B-B). Com uma ACT ICP-Brasil contratada (decisão do Samuel junto com o
e-CNPJ), grave o endereço dela no `.env.prod` e recrie o validador:

```bash
# Execute manualmente no terminal externo (na VPS)
cd /opt/jusprime && sed -i '/^ASSINATURA_SELO_TSA_URL=/d' .env.prod && printf 'ASSINATURA_SELO_TSA_URL=%s\n' 'https://endereco-da-act' >> .env.prod
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-deps validador_pdf
```

Troque `https://endereco-da-act` pelo endereço que a ACT informar. Se a ACT não responder, o selo dá
`carimbo_indisponivel` e o sistema tenta de novo.

## 5. Trocar ou desligar

- **Renovar o e-CNPJ** (o A1 vale um ano): repita os passos 1 a 3 com o arquivo e a senha novos.
- **Desligar o selo** sem desligar o validador: esvazie os dois arquivos (nunca os apague) e recrie o validador.

  ```bash
  # Execute manualmente no terminal externo (na VPS)
  cd /opt/jusprime/secrets && : > selo_pkcs12 && : > selo_pkcs12_senha && ls -ln selo_pkcs12 selo_pkcs12_senha
  cd /opt/jusprime && docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-deps --force-recreate validador_pdf
  ```

  **Esperado:** as duas linhas com tamanho `0`. A partir daí o PDF final volta a sair sem selo.

## 6. Dev

O `docker-compose.yml` de desenvolvimento não monta a chave do selo: em dev o validador sempre responde
`selo_nao_configurado` e o PDF final sai sem selo. O selo se testa com o certificado de teste gerado nos testes do
validador (`validador-pdf/testes`), nunca com o e-CNPJ real.
