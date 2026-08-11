// ARRANJO B — "Lista + trilho lateral" para `cobranca_carteira_show`.
//
// Fonte reproduzível do wireframe: cole em `mcp__open-pencil__render` (parâmetro `jsx`, x=1260, y=0,
// para ficar ao lado do arranjo A).
//
// ⚠️ NUNCA CHEGOU A RENDERIZAR. A chamada morreu com `RPC timeout (30s)` — o JSX é grande demais
// para uma única chamada do renderer. Se for retomar, **quebre em duas chamadas**: primeiro o
// frame raiz + header + KPIs, depois o corpo (`render` com `parent_id` do raiz). Isso é fato
// medido, não suposição: o arranjo A, que é menor, passou na mesma sessão.
//
// Ideia do arranjo: a lista ganha 800px (contra 8/12 de hoje) e Configuração + Documentos viram um
// trilho lateral compacto de 324px — visíveis sem clique (diferente do arranjo A, que os esconde
// atrás de aba), mas claramente secundários. Custo: a tabela perde ~340px, então a coluna PESSOA
// COBRADA fica apertada com nome longo.
//
// Compare com `arranjo-a.jsx`. A escolha entre os dois é: "Configuração/Documentos precisam estar
// visíveis o tempo todo?" Se sim, B. Se não, A dá quase 400px a mais para a lista.

<Frame name="B — Lista + trilho lateral" w={1180} h="hug" flex="col" gap={0} p={0} bg="#F5F6F8" rounded={0}>
  <Frame name="HeaderB" w="fill" h="hug" flex="col" gap={10} p={24} bg="#FFFFFF">
    <Frame name="TituloLinhaB" w="fill" h={38} flex="row" gap={12} p={0} bg="#FFFFFF">
      <Frame name="VoltarB" w={34} h={34} flex="row" gap={0} p={9} bg="#EEF0F3" rounded={8}><Text size={14} color="#495057">&lt;</Text></Frame>
      <Frame name="TituloBoxB" w={280} h={34} flex="row" gap={0} p={2} bg="#FFFFFF"><Text size={26} weight="bold" color="#14181C">TOP LIFE II</Text></Frame>
      <Frame name="EspacoB" w="fill" h={1} bg="#FFFFFF" />
      <Frame name="BtnImportarB" w={186} h={38} flex="row" gap={8} p={11} bg="#1F7A4D" rounded={8}><Text size={14} weight="semibold" color="#FFFFFF">Importar relatorio</Text></Frame>
    </Frame>
    <Frame name="MetaLinhaB" w="fill" h={26} flex="row" gap={10} p={0} bg="#FFFFFF">
      <Frame name="ClienteBoxB" w={190} h={26} flex="row" gap={0} p={5} bg="#FFFFFF"><Text size={14} color="#6C757D">Condominio Top Life II</Text></Frame>
      <Frame name="ChipFrescorB" w={310} h={26} flex="row" gap={6} p={6} bg="#F1F3F5" rounded={999}><Text size={12} color="#495057">Dados atualizados ate 10/08/2026 (hoje)</Text></Frame>
    </Frame>
  </Frame>

  <Frame name="KPIsB" w="fill" h="hug" flex="row" gap={0} p={0} bg="#FFFFFF">
    <Frame name="KpiSaldoB" w={320} h="hug" flex="col" gap={6} p={20} bg="#FFFFFF">
      <Text size={11} weight="semibold" color="#8A9199">SALDO CONSOLIDADO</Text>
      <Text size={30} weight="bold" color="#14181C">R$ 1.093.412,77</Text>
    </Frame>
    <Frame name="KpiVencidoB" w={300} h="hug" flex="col" gap={6} p={20} bg="#FFFFFF">
      <Text size={11} weight="semibold" color="#8A9199">VENCIDO</Text>
      <Text size={30} weight="bold" color="#C0392B">R$ 412.880,10</Text>
    </Frame>
    <Frame name="KpiObjetosB" w={230} h="hug" flex="col" gap={6} p={20} bg="#FFFFFF">
      <Text size={11} weight="semibold" color="#8A9199">OBJETOS COBRADOS</Text>
      <Text size={30} weight="bold" color="#14181C">121</Text>
    </Frame>
    <Frame name="KpiAcordoB" w={230} h="hug" flex="col" gap={6} p={20} bg="#FFFFFF">
      <Text size={11} weight="semibold" color="#8A9199">EM ACORDO</Text>
      <Text size={30} weight="bold" color="#14181C">37</Text>
    </Frame>
  </Frame>

  <Frame name="CorpoB" w="fill" h="hug" flex="row" gap={16} p={20} bg="#F5F6F8">
    <Frame name="CardListaB" w={800} h="hug" flex="col" gap={0} p={0} bg="#FFFFFF" rounded={12}>
      <Frame name="ToolbarB" w="fill" h={66} flex="row" gap={10} p={14} bg="#FFFFFF">
        <Frame name="BuscaB" w={330} h={38} flex="row" gap={8} p={11} bg="#F8F9FA" rounded={8}><Text size={13} color="#8A9199">Buscar objeto ou pessoa...</Text></Frame>
        <Frame name="OrdenarB" w={180} h={38} flex="row" gap={8} p={11} bg="#FFFFFF" rounded={8}><Text size={13} color="#495057">Maior saldo primeiro</Text></Frame>
        <Frame name="Espaco2B" w="fill" h={1} bg="#FFFFFF" />
        <Frame name="ContagemBoxB" w={90} h={38} flex="row" gap={0} p={11} bg="#FFFFFF"><Text size={13} color="#6C757D">121 objetos</Text></Frame>
      </Frame>
      <Frame name="TabelaHeadB" w="fill" h={38} flex="row" gap={0} p={0} bg="#FAFBFC">
        <Frame name="bh1" w={280} h={38} flex="row" gap={0} p={13} bg="#FAFBFC"><Text size={11} weight="semibold" color="#8A9199">OBJETO</Text></Frame>
        <Frame name="bh2" w={230} h={38} flex="row" gap={0} p={13} bg="#FAFBFC"><Text size={11} weight="semibold" color="#8A9199">PESSOA COBRADA</Text></Frame>
        <Frame name="bh3" w={120} h={38} flex="row" gap={0} p={13} bg="#FAFBFC"><Text size={11} weight="semibold" color="#8A9199">ESTADO</Text></Frame>
        <Frame name="bh4" w={170} h={38} flex="row" gap={0} p={13} bg="#FAFBFC" justify="end"><Text size={11} weight="semibold" color="#1F7A4D">SALDO  v</Text></Frame>
      </Frame>
      <Frame name="bLinha1" w="fill" h={50} flex="row" gap={0} p={0} bg="#FFFFFF">
        <Frame name="b11" w={280} h={50} flex="row" gap={0} p={15} bg="#FFFFFF"><Text size={14} weight="semibold" color="#14181C">UNIDADE 1204</Text></Frame>
        <Frame name="b12" w={230} h={50} flex="row" gap={0} p={15} bg="#FFFFFF"><Text size={14} color="#495057">Mariana Albuquerque</Text></Frame>
        <Frame name="b13" w={120} h={50} flex="row" gap={0} p={13} bg="#FFFFFF"><Frame name="bBadge1" w={82} h={24} flex="row" gap={0} p={5} bg="#FDECEA" rounded={6}><Text size={12} weight="semibold" color="#C0392B">Vencido</Text></Frame></Frame>
        <Frame name="b14" w={170} h={50} flex="row" gap={0} p={15} bg="#FFFFFF" justify="end"><Text size={15} weight="bold" color="#14181C">R$ 18.402,55</Text></Frame>
      </Frame>
      <Frame name="bLinha2" w="fill" h={50} flex="row" gap={0} p={0} bg="#FCFCFD">
        <Frame name="b21" w={280} h={50} flex="row" gap={0} p={15} bg="#FCFCFD"><Text size={14} weight="semibold" color="#14181C">UNIDADE 0803</Text></Frame>
        <Frame name="b22" w={230} h={50} flex="row" gap={0} p={15} bg="#FCFCFD"><Text size={14} color="#495057">Roberto Cavalcanti</Text></Frame>
        <Frame name="b23" w={120} h={50} flex="row" gap={0} p={13} bg="#FCFCFD"><Frame name="bBadge2" w={96} h={24} flex="row" gap={0} p={5} bg="#E8F3ED" rounded={6}><Text size={12} weight="semibold" color="#1F7A4D">Em acordo</Text></Frame></Frame>
        <Frame name="b24" w={170} h={50} flex="row" gap={0} p={15} bg="#FCFCFD" justify="end"><Text size={15} weight="bold" color="#14181C">R$ 9.117,40</Text></Frame>
      </Frame>
      <Frame name="bLinha3" w="fill" h={50} flex="row" gap={0} p={0} bg="#FFFFFF">
        <Frame name="b31" w={280} h={50} flex="row" gap={0} p={15} bg="#FFFFFF"><Text size={14} weight="semibold" color="#14181C">UNIDADE 0301</Text></Frame>
        <Frame name="b32" w={230} h={50} flex="row" gap={0} p={15} bg="#FFFFFF"><Text size={14} color="#495057">Helena Pecanha</Text></Frame>
        <Frame name="b33" w={120} h={50} flex="row" gap={0} p={13} bg="#FFFFFF"><Frame name="bBadge3" w={72} h={24} flex="row" gap={0} p={5} bg="#F1F3F5" rounded={6}><Text size={12} weight="semibold" color="#6C757D">Em dia</Text></Frame></Frame>
        <Frame name="b34" w={170} h={50} flex="row" gap={0} p={15} bg="#FFFFFF" justify="end"><Text size={15} weight="bold" color="#14181C">R$ 3.240,00</Text></Frame>
      </Frame>
      <Frame name="RodapeB" w="fill" h={52} flex="row" gap={10} p={15} bg="#FAFBFC">
        <Frame name="InfoBoxB" w={190} h={32} flex="row" gap={0} p={8} bg="#FAFBFC"><Text size={13} color="#6C757D">Mostrando 1-25 de 121</Text></Frame>
        <Frame name="e4B" w="fill" h={1} bg="#FAFBFC" />
        <Frame name="PgPrevB" w={34} h={32} flex="row" gap={0} p={8} bg="#FFFFFF" rounded={6}><Text size={13} color="#ADB5BD">&lt;</Text></Frame>
        <Frame name="PgInfoB" w={44} h={32} flex="row" gap={0} p={8} bg="#FAFBFC"><Text size={13} weight="semibold" color="#495057">1 / 5</Text></Frame>
        <Frame name="PgNextB" w={34} h={32} flex="row" gap={0} p={8} bg="#FFFFFF" rounded={6}><Text size={13} color="#495057">&gt;</Text></Frame>
      </Frame>
    </Frame>

    <Frame name="TrilhoB" w={324} h="hug" flex="col" gap={16} p={0} bg="#F5F6F8">
      <Frame name="CardConfigB" w="fill" h="hug" flex="col" gap={0} p={0} bg="#FFFFFF" rounded={12}>
        <Frame name="ConfigHeadB" w="fill" h={48} flex="row" gap={0} p={15} bg="#FFFFFF">
          <Frame name="ConfigTitB" w={150} h={22} flex="row" gap={0} p={2} bg="#FFFFFF"><Text size={14} weight="semibold" color="#14181C">Configuracao</Text></Frame>
          <Frame name="e5B" w="fill" h={1} bg="#FFFFFF" />
          <Frame name="BtnEditB" w={62} h={28} flex="row" gap={0} p={7} bg="#F1F3F5" rounded={6}><Text size={12} color="#495057">Editar</Text></Frame>
        </Frame>
        <Frame name="ConfLin1" w="fill" h={34} flex="row" gap={0} p={9} bg="#FFFFFF">
          <Frame name="cl1a" w={150} h={20} flex="row" gap={0} p={2} bg="#FFFFFF"><Text size={13} color="#8A9199">Modo</Text></Frame>
          <Frame name="cl1b" w={130} h={20} flex="row" gap={0} p={2} bg="#FFFFFF" justify="end"><Text size={13} color="#14181C">Condominio</Text></Frame>
        </Frame>
        <Frame name="ConfLin2" w="fill" h={34} flex="row" gap={0} p={9} bg="#FCFCFD">
          <Frame name="cl2a" w={150} h={20} flex="row" gap={0} p={2} bg="#FCFCFD"><Text size={13} color="#8A9199">Honorarios</Text></Frame>
          <Frame name="cl2b" w={130} h={20} flex="row" gap={0} p={2} bg="#FCFCFD" justify="end"><Text size={13} color="#14181C">Percentual (20%)</Text></Frame>
        </Frame>
        <Frame name="ConfLin3" w="fill" h={34} flex="row" gap={0} p={9} bg="#FFFFFF">
          <Frame name="cl3a" w={150} h={20} flex="row" gap={0} p={2} bg="#FFFFFF"><Text size={13} color="#8A9199">Tolerancia</Text></Frame>
          <Frame name="cl3b" w={130} h={20} flex="row" gap={0} p={2} bg="#FFFFFF" justify="end"><Text size={13} color="#14181C">5 dias</Text></Frame>
        </Frame>
        <Frame name="ConfLin4" w="fill" h={34} flex="row" gap={0} p={9} bg="#FCFCFD">
          <Frame name="cl4a" w={150} h={20} flex="row" gap={0} p={2} bg="#FCFCFD"><Text size={13} color="#8A9199">Rotulo do objeto</Text></Frame>
          <Frame name="cl4b" w={130} h={20} flex="row" gap={0} p={2} bg="#FCFCFD" justify="end"><Text size={13} color="#14181C">Unidade</Text></Frame>
        </Frame>
      </Frame>

      <Frame name="CardDocsB" w="fill" h="hug" flex="col" gap={0} p={0} bg="#FFFFFF" rounded={12}>
        <Frame name="DocsHeadB" w="fill" h={48} flex="row" gap={0} p={15} bg="#FFFFFF">
          <Frame name="DocsTitB" w={150} h={22} flex="row" gap={0} p={2} bg="#FFFFFF"><Text size={14} weight="semibold" color="#14181C">Documentos  3</Text></Frame>
          <Frame name="e6B" w="fill" h={1} bg="#FFFFFF" />
          <Frame name="BtnAddDocB" w={34} h={28} flex="row" gap={0} p={7} bg="#F1F3F5" rounded={6}><Text size={12} color="#495057">+</Text></Frame>
        </Frame>
        <Frame name="DocLin1" w="fill" h={44} flex="col" gap={1} p={9} bg="#FFFFFF">
          <Text size={13} color="#14181C">contrato-assembleia.pdf</Text>
          <Text size={11} color="#8A9199">Contrato  ·  02/08/2026</Text>
        </Frame>
        <Frame name="DocLin2" w="fill" h={44} flex="col" gap={1} p={9} bg="#FCFCFD">
          <Text size={13} color="#14181C">ata-2026-07.pdf</Text>
          <Text size={11} color="#8A9199">Ata  ·  28/07/2026</Text>
        </Frame>
        <Frame name="DocLin3" w="fill" h={44} flex="col" gap={1} p={9} bg="#FFFFFF">
          <Text size={13} color="#14181C">procuracao.pdf</Text>
          <Text size={11} color="#8A9199">Procuracao  ·  15/07/2026</Text>
        </Frame>
      </Frame>
    </Frame>
  </Frame>
</Frame>
