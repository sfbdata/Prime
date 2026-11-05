---
tipo: correcao
---
## Changelog
- Pasta oculta e aba oculta (auditoria de segurança, lote F2: 1.07, 1.11, 2.04, 1.12, 2.05, 3.18):
  - 1.07: o aviso de pasta duplicada (`POST /pasta/nova`) e o total dele deixam de fora as pastas ocultas pelo cadeado para quem cria (`findSemelhantesPorClienteEAcao` e `contarSemelhantesPorClienteEAcao` recebem os ids ocultos de `AcessoAPasta::idsDasPastasOcultas`).
  - 1.11: a média do valor da causa por cliente (aba Financeiro, `valor-causa` e cliente principal) não soma as pastas ocultas para quem olha. Primário e Master seguem com a média de todas.
  - 2.04: com a aba Financeiro oculta, as respostas de cliente principal, vincular, cadastrar e desvincular cliente voltam com `mediaFormatada` e `mediaRotulo` nulos (`VisibilidadeDoFinanceiroDaPasta::podeVer`).
  - 1.12: o aviso de arquivo duplicado no upload do Peticionar respeita as abas ocultas da pasta onde o duplicado mora: com Documentos oculta, nada daquela pasta; com Financeiro oculta, sem o contrato de honorários.
  - 2.05: a barreira das abas ocultas passa a cobrir `pasta_push_cliente*`, `pasta_push_monitoramento`, `pasta_push_buscar`, `pasta_push_avisos` (Push) e `pasta_modelos_*` (Modelos). Teste novo falha se surgir rota em `/pasta/{id}/push/` ou `/pasta/{id}/modelos` fora do mapa.
  - 3.18: `RegistrarPagamentoDaPastaUseCase` recusa pasta de outro escritório (`DomainException`, 404 no controller).
  - Testes: `PastaOcultaNaMediaENaDuplicadaTest`, `PeticionarUploadDuplicadoControllerTest`, `BarreiraDeAbaOcultaListenerTest`, `BarreiraDeAbaOcultaMapaTest`, `RegistrarPagamentoDaPastaUseCaseTest`.

## Para o cliente
- O aviso "já existe uma pasta parecida" não cita mais pastas que estão ocultas para quem está criando a pasta nova.
- A média do valor da causa por cliente passa a contar só as pastas que a pessoa pode ver.
- Quem tem a aba Financeiro oculta numa pasta não recebe mais a média do cliente ao trocar ou vincular clientes nessa pasta.
- O aviso de arquivo repetido no upload não mostra mais documentos de abas ocultas para a pessoa.
- As telas de avisos ao cliente, monitoramento do Push e Modelos passam a respeitar a aba oculta, como as outras partes da pasta.
