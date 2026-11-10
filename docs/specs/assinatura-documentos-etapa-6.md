# Assinatura de documentos, etapa 6: cadeia gov.br e ICP-Brasil no servidor (pyHanko)

Spec geral: `docs/specs/assinatura-documentos.md` (3.2, 3.3, etapa 6, D2). Motor de integridade já pronto:
`docs/specs/assinatura-documentos-etapa-5-motor.md` (M1 a M27). Etapa 5 (gov.br, ICP e documento já assinado):
`docs/specs/assinatura-documentos-etapa-5.md`. Branch `assinatura-desenho`, PR único. Sem migration nova: as colunas da
cadeia entram na M4 (`Version20261109300000`) da etapa 5.

## 1. Decisões

| # | Assunto | Decisão |
|---|---|---|
| E6-1 | Onde roda | Contêiner próprio `validador_pdf` (pasta `validador-pdf/` na raiz), Python 3.12 com pyHanko, imagem com versão fixada e dependências com hash (`--require-hashes`), usuário sem root, sistema de arquivos só de leitura e `/tmp` em tmpfs. O PHP não ganha Python. |
| E6-2 | Redes | `assinatura_net` (`internal: true`) só com `php`, `worker` e `validador_pdf`; `validador_saida` só para o validador baixar LCR, OCSP e as cadeias do ITI. O validador nunca entra na `saas_net` (lá roda o `blog_builder` com npm de terceiros). Sem porta publicada. |
| E6-3 | Ligar em produção | `profiles: ["assinatura"]`, como o `voto`: o deploy só sobe com `COMPOSE_PROFILES=assinatura` (passo do Samuel, runbook). Dev: mesmo serviço com o mesmo profile; a suíte PHP não depende dele. |
| E6-4 | gov.br e ICP só com validador | Sem `ASSINATURA_VALIDADOR_URL` configurada, o envio recusa os níveis gov.br e ICP (sem a cadeia, um certificado autoassinado com qualquer CPF passaria). Validador fora do ar na hora de enviar o PDF assinado: recusa com "tente de novo em alguns minutos"; nada é gravado como avançada ou qualificada sem cadeia confirmada. |
| E6-5 | Âncoras | ICP-Brasil: pacote oficial do ITI (`ACcompactado.zip`, conferido pelo `hashsha512.txt` publicado ao lado antes de extrair); só certificados autoassinados viram raiz, os demais são intermediárias. gov.br: raiz própria (não é ICP-Brasil), com a impressão digital SHA-256 fixada no código; nunca confiar em certificado que vem dentro do PDF. Atualização ao subir e a cada 24 h; download que falha mantém a última boa, guardada no volume `validador_ancoras`. |
| E6-6 | Regra de combinação (no PHP) | Motor `ilegivel`, `sem_assinatura`, alguma `alterada`, prefixo que não confere ou nenhuma assinatura nova: recusa sem chamar o validador. Senão o validador decide cada assinatura nova: aceita só com `intacta`, `valida`, `confiavel` com a âncora do nível (ICP também vale para quem é gov.br), revogação `boa`, `cobertura` do arquivo inteiro, `nivel_de_modificacao` até `formulario` e `docmdp_ok` não falso. Só então o CPF que o motor leu passa a valer. |
| E6-7 | O que fica gravado | O objeto de cada assinatura devolvido pelo validador vai para a coluna `cadeia` (JSON) da versão, com `cadeia_situacao` (`confirmada`, `recusada`, `indisponivel`) e `cadeia_conferida_em`. LTV dentro do arquivo (DSS) fica de fora: muda os bytes guardados. |

## 2. Protocolo HTTP `v1` (contrato entre PHP e contêiner)

- Base: `http://validador_pdf:8080`. Autenticação: cabeçalho `Authorization: Bearer {ASSINATURA_VALIDADOR_TOKEN}`,
  comparado em tempo constante; sem ele ou errado, `401`. Tokens e URLs: env `ASSINATURA_VALIDADOR_URL` e
  `ASSINATURA_VALIDADOR_TOKEN` (vazios = não configurado).
- `GET /saude` (sem token): `200 {"v":1,"ok":true,"ancoras":{"icp":N,"govbr":N,"atualizadas_em":"ISO-8601"|null}}`.
- `POST /v1/conferir?ancora=icp|govbr|qualquer`, corpo = bytes do PDF (`Content-Type: application/pdf`), teto 20 MB
  (`413`), tempo máximo 30 s, até 2 conferências ao mesmo tempo (fila de 10 s, depois `503`).
- Resposta `200`:

```json
{"v":1,"assinaturas":[{
  "campo":"Signature1","faixa":[0,840,960,1200],
  "intacta":true,"valida":true,"confiavel":true,"ancora":"icp",
  "titular":{"cn":"...","cpf":"12345678900"|null,"cnpj":null},
  "cadeia":[{"cn":"...","sha256":"..."}],
  "revogacao":"boa|revogada|desconhecida",
  "carimbo":{"presente":true,"valido":true,"hora":"ISO-8601"},
  "cobertura":"arquivo_inteiro|revisao_inteira|parcial",
  "nivel_de_modificacao":"nenhuma|ltv|formulario|anotacoes|outra",
  "docmdp_ok":true|null,"motivo":null}]}
```

  O PHP casa cada item com a assinatura do motor pela `faixa` (`/ByteRange`).
- Erros: `400 {"v":1,"erro":"pdf_ilegivel"}`, `401`, `413`, `503 {"v":1,"erro":"ocupado"}`. Nunca devolve o PDF
  nem registra conteúdo em log (só tamanho, tempo e resultado).

## 3. Blocos

| Bloco | Escopo | Arquivos | Depende de |
|---|---|---|---|
| 6A Contêiner | `validador-pdf/`: Dockerfile em dois alvos (`producao` e `teste`), `requirements.txt` e `requirements-teste.txt` com hash, servidor HTTP da biblioteca padrão, âncoras, testes Python com PKI de teste gerada no teste (sem internet: `allow_fetching=False`, LCR e OCSP locais). Casos: cadeia boa, raiz desconhecida, revogado, vencido, carimbo válido e inválido, acréscimo que muda página, sombra. | só `validador-pdf/**` | este protocolo |
| 6B Cliente PHP | `Service/Cadeia/HttpValidadorDeCadeia` no lugar da implementação "não configurado" da 5.0, cliente HTTP nomeado, regra E6-6, gravação E6-7, classe do manifesto só com cadeia confirmada; testes com `MockHttpClient` | `Service/Cadeia/**`, `assinatura_eletronica.yaml`, alias em `services.yaml`, chamada no `AssinarComCertificadoUseCase` | 5.0 e 5C |
| 6C Infra | serviço nos dois compose, redes, volume, `profiles`, teste de arranjo do compose, job do CI que roda os testes Python na imagem `teste`, `ConferenteDeSegredos`, `.env*` (nomes vazios), runbook `docs/runbooks/assinatura-validador-em-producao.md` | `docker-compose.yml`, `docker-compose.prod.yml`, `.github/workflows/ci.yml`, teste de arquitetura | 6A (avisar o Coordenador antes: arquivos quentes) |

## 4. Pendências do Samuel

- Assinar um PDF de teste no assinador.iti.br (conta gov.br) e outro com certificado ICP-Brasil A1/A3, para o aceite.
- Depois do merge: criar `ASSINATURA_VALIDADOR_TOKEN`, ligar o profile `assinatura` (runbook da 6C).
