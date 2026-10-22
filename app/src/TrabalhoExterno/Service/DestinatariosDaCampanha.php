<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\DTO\FiltroDaCampanha;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\InteressadoEmVagas;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\InteressadoEmVagasRepository;
use App\TrabalhoExterno\Repository\VagaRepository;

/**
 * Quem recebe a campanha por e-mail do Master (`dest` do `campDe`, desenho 06 l.1083; spec VS 18.10.26
 * §4.10). A regra mora SÓ aqui: a tela, o CSV e o log contam o que esta classe devolve.
 *
 * - Candidatos: currículos com o aceite LGPD, nos filtros de UF e área, desligados por
 *   {@see CURRICULOS_LIBERADOS} (o aceite do currículo cobre só compartilhar com quem publica a vaga,
 *   não e-mail de campanha); até o dono decidir ficam de fora e a tela diz quantos. Os inscritos nos alertas da
 *   página pública só entram com o e-mail confirmado (double opt-in da 5d); até lá ficam de fora e a
 *   tela diz quantos e por quê.
 * - Escritórios: os e-mails dos ofertantes das vagas, desligados por {@see ESCRITORIOS_LIBERADOS}, e os
 *   inscritos nos alertas, como no desenho (l.1083), com a mesma regra da confirmação.
 * - Conta desativada: o currículo nunca entra, nem conta no "de fora".
 * - Sem duplicados: o e-mail em minúsculas é a chave; o primeiro que entra fica.
 */
final class DestinatariosDaCampanha
{
    /** A confirmar com o dono: interesse legítimo B2B para mandar campanha ao e-mail dos ofertantes. */
    public const ESCRITORIOS_LIBERADOS = false;
    /**
     * A confirmar com o dono: o aceite do currículo ("compartilhá-los apenas com quem publica as vagas a que
     * eu me candidatar") não cobre e-mail de campanha. Opções: (a) aceite específico de campanha no
     * formulário do currículo, (b) só os inscritos confirmados da 5d, (c) aceitar o risco.
     */
    public const CURRICULOS_LIBERADOS = false;
    public const MOTIVO_CURRICULOS = '%d currículo(s) ficam de fora: o aceite do currículo não cobre e-mail de campanha.';
    public const DICA_ESCRITORIOS = 'A confirmar';
    public const MOTIVO_INSCRITOS = '%d inscrito(s) nos alertas ficam de fora até confirmarem o e-mail.';
    public const ORIGEM_CURRICULO = 'Currículo';
    public const ORIGEM_ALERTA = 'Alerta de vagas';
    public const ORIGEM_ESCRITORIO = 'Escritório';

    public function __construct(
        private readonly CurriculoRepository $curriculos,
        private readonly InteressadoEmVagasRepository $interessados,
        private readonly VagaRepository $vagas,
        /** Só o teste troca: em produção vale a constante. */
        private readonly bool $escritoriosLiberados = self::ESCRITORIOS_LIBERADOS,
        /** Só o teste troca: em produção vale a constante. */
        private readonly bool $curriculosLiberados = self::CURRICULOS_LIBERADOS,
    ) {
    }

    /**
     * Os botões de público do modal (`cpPubs`, l.1169). O de Escritórios aparece desabilitado, com a
     * dica, enquanto a regra estiver desligada.
     *
     * @return list<array{k: string, rot: string, sub: string, ic: string, disponivel: bool, dica?: string}>
     */
    public function publicos(): array
    {
        $escritorios = ['k' => FiltroDaCampanha::ESCRITORIOS, 'rot' => 'Escritórios', 'sub' => 'Divulgue sua vaga grátis', 'ic' => 'bi-building', 'disponivel' => $this->escritoriosLiberados];

        return [
            ['k' => FiltroDaCampanha::CANDIDATOS, 'rot' => 'Candidatos', 'sub' => 'Vagas abertas + criar conta', 'ic' => 'bi-person-badge', 'disponivel' => true],
            $this->escritoriosLiberados ? $escritorios : $escritorios + ['dica' => self::DICA_ESCRITORIOS],
        ];
    }

    /**
     * A lista do público, sem duplicados, e quem ficou de fora com o motivo.
     *
     * @return array{lista: list<array{email: string, nome: string, origem: string, uf: string}>, deFora: list<array{n: int, motivo: string}>}
     */
    public function para(FiltroDaCampanha $filtro): array
    {
        $curriculos = $filtro->ehEscritorios() ? [] : $this->doBancoDeTalentos($filtro);
        $base = $filtro->ehEscritorios()
            ? ($this->escritoriosLiberados ? $this->ofertantes() : [])
            : ($this->curriculosLiberados ? $curriculos : []);
        [$lista, $pendentes] = $this->comInscritos($filtro, $base, $curriculos);

        return ['lista' => $this->semDescadastrados($lista), 'deFora' => $this->deFora($this->curriculosLiberados ? 0 : \count($curriculos), $pendentes)];
    }

    /**
     * Os inscritos nos alertas entram nos dois públicos, como no desenho (l.1083), com o filtro do desenho.
     * Só o confirmado entra na lista; o pendente conta no "de fora". Quem já está na lista, ou já contou
     * como currículo, não conta duas vezes.
     *
     * @param array<string, array{email: string, nome: string, origem: string, uf: string}> $lista
     * @param array<string, mixed>                                                         $curriculos
     *
     * @return array{0: array<string, array{email: string, nome: string, origem: string, uf: string}>, 1: int}
     */
    private function comInscritos(FiltroDaCampanha $filtro, array $lista, array $curriculos): array
    {
        $pendentes = 0;
        foreach ($this->interessados->paraExportar() as $i) {
            if (!self::inscritoNoFiltro($i, $filtro) || isset($curriculos[$i->getEmail()]) || isset($lista[$i->getEmail()])) {
                continue;
            }
            if (!$this->inscritoConfirmado($i)) {
                ++$pendentes;
                continue;
            }
            $lista[$i->getEmail()] = ['email' => $i->getEmail(), 'nome' => '', 'origem' => self::ORIGEM_ALERTA, 'uf' => $i->getUf()];
        }

        return [$lista, $pendentes];
    }

    /** @return list<array{n: int, motivo: string}> */
    private function deFora(int $curriculos, int $inscritos): array
    {
        $fora = [];
        if ($curriculos > 0) {
            $fora[] = ['n' => $curriculos, 'motivo' => sprintf(self::MOTIVO_CURRICULOS, $curriculos)];
        }
        if ($inscritos > 0) {
            $fora[] = ['n' => $inscritos, 'motivo' => sprintf(self::MOTIVO_INSCRITOS, $inscritos)];
        }

        return $fora;
    }

    /**
     * O inscrito nos alertas confirmou o e-mail? Liga com a confirmação da 5d (double opt-in): até ela
     * existir, ninguém está confirmado e todos ficam de fora.
     */
    public function inscritoConfirmado(InteressadoEmVagas $inscrito): bool
    {
        return false;
    }

    /** @return array<string, array{email: string, nome: string, origem: string, uf: string}> */
    private function doBancoDeTalentos(FiltroDaCampanha $filtro): array
    {
        $lista = [];
        foreach ($this->curriculos->recebidos() as $cv) {
            $email = mb_strtolower(trim($cv->texto('email')));
            if ($email === '' || isset($lista[$email]) || !$cv->getUsuario()->isActive() || !self::comAceiteLgpd($cv) || !self::curriculoNoFiltro($cv, $filtro)) {
                continue;
            }
            $lista[$email] = ['email' => $email, 'nome' => $cv->texto('nome'), 'origem' => self::ORIGEM_CURRICULO, 'uf' => $cv->texto('uf')];
        }

        return $lista;
    }

    /**
     * O e-mail de quem oferece cada vaga (`v.of.email`, l.1083), sem filtro de UF nem de área, como no
     * desenho.
     *
     * @return array<string, array{email: string, nome: string, origem: string, uf: string}>
     */
    private function ofertantes(): array
    {
        $lista = [];
        foreach ($this->vagas->daCentral() as $v) {
            $o = $v->getOfertante();
            $email = mb_strtolower(trim(is_string($o['email'] ?? null) ? $o['email'] : ''));
            if ($email === '' || isset($lista[$email])) {
                continue;
            }
            $lista[$email] = ['email' => $email, 'nome' => self::campo($o, 'nome'), 'origem' => self::ORIGEM_ESCRITORIO, 'uf' => self::campo($o, 'uf')];
        }

        return $lista;
    }

    /**
     * Ponto de troca do descadastro da 0d: quando o serviço entrar no master, quem se descadastrou sai
     * da lista aqui (um filtro só, para os dois públicos). Hoje a lista passa inteira.
     *
     * @param array<string, array{email: string, nome: string, origem: string, uf: string}> $lista
     *
     * @return list<array{email: string, nome: string, origem: string, uf: string}>
     */
    private function semDescadastrados(array $lista): array
    {
        return array_values($lista);
    }

    /** Todo currículo salvo passou pelo aceite LGPD do formulário (l.282), com a versão do texto. */
    private static function comAceiteLgpd(Curriculo $cv): bool
    {
        return $cv->getVersaoDoConsentimento() !== '';
    }

    private static function curriculoNoFiltro(Curriculo $cv, FiltroDaCampanha $f): bool
    {
        return ($f->uf === '' || $cv->texto('uf') === $f->uf)
            && ($f->area === '' || in_array($f->area, $cv->getAreas(), true));
    }

    /** Como no desenho: o inscrito sem UF (ou sem área) vale para qualquer UF (ou área). */
    private static function inscritoNoFiltro(InteressadoEmVagas $i, FiltroDaCampanha $f): bool
    {
        return ($f->uf === '' || $i->getUf() === '' || $i->getUf() === $f->uf)
            && ($f->area === '' || $i->getAreas() === [] || in_array($f->area, $i->getAreas(), true));
    }

    /** @param array<string, mixed> $o */
    private static function campo(array $o, string $chave): string
    {
        return is_string($o[$chave] ?? null) ? $o[$chave] : '';
    }
}
