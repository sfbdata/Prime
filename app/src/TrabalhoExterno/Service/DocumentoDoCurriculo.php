<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\SituacaoAcademica;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * O currículo formatado (`cvHTML`, desenho 06 l.920 a 938): o mesmo template serve a
 * pré-visualização em HTML e o PDF do Dompdf. O Dompdf não desenha grade nem flex, então o
 * template troca a moldura por tabela quando `pdf` está ligado; as seções e os textos são os mesmos.
 *
 * No PDF, o que a biblioteca enxerga é só a área temporária desta execução (a foto, lida por CHAVE)
 * e a pasta do logotipo estático do sistema: `chroot` nas duas, sem rede e só `file://` (skill de
 * arquivos, E2.6C).
 */
final class DocumentoDoCurriculo
{
    private const TEMPLATE = 'trabalho_externo/curriculo_documento.html.twig';
    private const LOGO = '/public/images/logo/bluejus-logo-nav.png';

    public function __construct(
        private readonly Environment $twig,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** O currículo salvo. @return array<string, mixed> */
    public function dadosDe(Curriculo $cv): array
    {
        $campos = [];
        foreach (['nome', 'email', 'tel', 'insta', 'oabNum', 'oabUf', 'inst', 'sem', 'cargo', 'cidade', 'uf', 'modalidade', 'resumo', 'pretensao', 'vol', 'tituloP', 'linkedin', 'lattes', 'habs'] as $campo) {
            $campos[$campo] = $cv->texto($campo);
        }

        return $this->montar($campos + [
            'sit' => $cv->getSituacao(),
            'anos' => $cv->getAnos(),
            'exps' => $cv->itens('exps'),
            'forms' => $cv->itens('forms'),
            'idis' => $cv->itens('idis'),
            'areas' => $cv->getAreas(),
        ]);
    }

    /** "Pré-visualizar" do formulário: os dados ainda não salvos. @return array<string, mixed> */
    public function dadosDaPrevia(DadosDoCurriculo $d): array
    {
        return $this->montar([
            'nome' => $d->nome, 'email' => $d->email, 'tel' => $d->tel, 'insta' => $d->insta,
            'oabNum' => $d->oabNum, 'oabUf' => $d->oabUf, 'inst' => $d->inst, 'sem' => $d->sem,
            'cargo' => $d->cargo, 'cidade' => $d->cidade, 'uf' => $d->uf, 'modalidade' => $d->modalidade,
            'resumo' => $d->resumo, 'pretensao' => $d->pretensao, 'vol' => $d->vol, 'tituloP' => $d->tituloP,
            'linkedin' => $d->linkedin, 'lattes' => $d->lattes, 'habs' => $d->habs, 'sit' => $d->situacao,
            'anos' => $d->anos, 'exps' => $d->exps, 'forms' => $d->forms, 'idis' => $d->idis, 'areas' => $d->areas,
        ]);
    }

    /** @param array<string, mixed> $x */
    public function html(array $x, ?Curriculo $comFoto): string
    {
        $foto = $comFoto !== null && $comFoto->temArquivo(TipoArquivoDoCurriculo::Foto)
            ? $this->urls->generate('trabalho_externo_curriculo_baixar', ['codigo' => $comFoto->getCodigo(), 'tipo' => TipoArquivoDoCurriculo::Foto->value])
            : null;

        return $this->twig->render(self::TEMPLATE, ['x' => $x, 'pdf' => false, 'foto' => $foto, 'logo' => null]);
    }

    /**
     * O "Word" (`mcWord`): o HTML da pré-visualização aberto fora do sistema, sem sessão. A foto vem do
     * armazenamento privado (a rota de download pede login), então vai embutida como `data:`, lida por CHAVE;
     * o logotipo e o símbolo são estáticos e públicos e vão com a URL absoluta.
     *
     * @param array<string, mixed> $x
     */
    public function word(array $x, Curriculo $cv): string
    {
        $foto = null;
        if ($cv->temArquivo(TipoArquivoDoCurriculo::Foto)) {
            $bytes = $this->armazenamento->ler(ChavesDeTrabalhoExterno::arquivoDoCurriculo($cv, TipoArquivoDoCurriculo::Foto));
            $tipo = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes);
            $foto = 'data:' . ($tipo === 'image/png' ? 'image/png' : 'image/jpeg') . ';base64,' . base64_encode($bytes);
        }

        return $this->twig->render(self::TEMPLATE, ['x' => $x, 'pdf' => false, 'word' => true, 'foto' => $foto, 'logo' => null]);
    }

    /** @param array<string, mixed> $x */
    public function pdf(array $x, Curriculo $cv): string
    {
        $area = AreaTemporariaPrivada::criar('curriculopdf');
        try {
            $foto = $cv->temArquivo(TipoArquivoDoCurriculo::Foto)
                ? $area->gravar($this->armazenamento->ler(ChavesDeTrabalhoExterno::arquivoDoCurriculo($cv, TipoArquivoDoCurriculo::Foto)), 'jpg')
                : null;
            $logo = is_file($this->projectDir . self::LOGO) ? $this->projectDir . self::LOGO : null;
            $html = $this->twig->render(self::TEMPLATE, ['x' => $x, 'pdf' => true, 'foto' => $foto, 'logo' => $logo]);

            return $this->renderizar($html, $area);
        } finally {
            $area->liberar();
        }
    }

    private function renderizar(string $html, AreaTemporariaPrivada $area): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        // A área desta execução (a foto) e a pasta do logotipo estático do sistema; nada mais.
        $options->set('chroot', [$area->caminho(), \dirname($this->projectDir . self::LOGO)]);
        $options->set('tempDir', $area->caminho());
        $options->setAllowedProtocols(['file://']);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Os blocos do `cvHTML` já resolvidos, para o template só desenhar.
     *
     * @param array<string, mixed> $c
     *
     * @return array<string, mixed>
     */
    private function montar(array $c): array
    {
        /** @var SituacaoAcademica|null $sit */
        $sit = $c['sit'];

        return [
            'nome' => $c['nome'],
            'email' => $c['email'],
            'iniciais' => ApresentadorDeCurriculo::iniciais((string) $c['nome']),
            'contato' => $this->contato($c, $sit),
            'habilidades' => array_values(array_filter(array_map('trim', explode(',', (string) $c['habs'])), static fn (string $h): bool => $h !== '')),
            'idiomas' => $c['idis'],
            'disponibilidade' => array_values(array_filter([
                $c['modalidade'] !== '' ? ['Modalidade', $c['modalidade']] : null,
                $c['pretensao'] !== '' ? ['Pretensão', $c['pretensao']] : null,
                $c['vol'] === 'sim' ? ['Voluntariado', 'Tem interesse'] : null,
            ])),
            'titulos' => $this->titulos((string) ($c['tituloP'] !== '' ? $c['tituloP'] : $c['cargo'])),
            'situacao' => implode(' · ', array_filter([$sit?->rotuloDoDocumento() ?? '', is_int($c['anos']) && $c['anos'] > 0 ? $c['anos'] . ' ano(s) de experiência' : ''])),
            'resumo' => $c['resumo'],
            'experiencias' => array_map(static fn (array $e): array => $e + ['periodo' => self::periodo($e['ini'] ?? '', $e['fim'] ?? '')], $c['exps']),
            'formacoes' => $this->formacoes($c, $sit),
            'areas' => $c['areas'],
        ];
    }

    /**
     * @param array<string, mixed> $c
     *
     * @return list<array{0: string, 1: string}>
     */
    private function contato(array $c, ?SituacaoAcademica $sit): array
    {
        $semProtocolo = static fn (string $url): string => (string) preg_replace('#^https?://(www\.)?#i', '', $url);
        $cidade = implode(' / ', array_filter([(string) $c['cidade'], (string) $c['uf']], static fn (string $v): bool => $v !== ''));

        return array_values(array_filter([
            $c['tel'] !== '' ? ['Telefone', $c['tel']] : null,
            $c['email'] !== '' ? ['E-mail', $c['email']] : null,
            $cidade !== '' ? ['Cidade', $cidade] : null,
            $c['oabNum'] !== '' ? [$sit === SituacaoAcademica::EstOab ? 'OAB (estagiário)' : 'OAB', $c['oabNum'] . ($c['oabUf'] !== '' ? '/' . $c['oabUf'] : '')] : null,
            $c['insta'] !== '' ? ['Instagram', '@' . $c['insta']] : null,
            $c['linkedin'] !== '' ? ['LinkedIn', $semProtocolo((string) $c['linkedin'])] : null,
            $c['lattes'] !== '' ? ['Lattes', $semProtocolo((string) $c['lattes'])] : null,
        ]));
    }

    /** "Profissões separadas por |" (decisão 9): cada uma limpa, juntas pelo mesmo separador. */
    private function titulos(string $titulo): string
    {
        return implode(' | ', array_filter(array_map('trim', explode('|', $titulo)), static fn (string $t): bool => $t !== ''));
    }

    /**
     * A formação do formulário e, se a instituição do bloco do curso não estiver nela, a linha que o
     * desenho acrescenta ("Direito · 5º semestre", "em curso"; ou "Bacharel em Direito").
     *
     * @param array<string, mixed> $c
     *
     * @return list<array<string, string>>
     */
    private function formacoes(array $c, ?SituacaoAcademica $sit): array
    {
        $forms = $c['forms'];
        $inst = (string) $c['inst'];
        if ($inst !== '' && !in_array($inst, array_column($forms, 'inst'), true)) {
            $bach = $sit === SituacaoAcademica::Bach;
            $forms[] = [
                'curso' => $bach ? 'Bacharel em Direito' : 'Direito' . ($c['sem'] !== '' ? ' · ' . $c['sem'] : ''),
                'inst' => $inst,
                'ini' => '',
                'fim' => $bach ? (string) $c['sem'] : 'em curso',
            ];
        }

        return array_map(static fn (array $f): array => $f + ['periodo' => self::periodo($f['ini'] ?? '', $f['fim'] ?? '')], $forms);
    }

    /** "2019 a 2021", ou só um dos dois (o desenho junta com travessão, que a tela não usa). */
    private static function periodo(string $ini, string $fim): string
    {
        return implode(' a ', array_filter([$ini, $fim], static fn (string $v): bool => $v !== ''));
    }
}
