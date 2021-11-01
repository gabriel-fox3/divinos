<?php

namespace Mubbi;
use Brick\Money\Money;

class ControllerFinanceiroHome extends BaseController {
  public function index($data = null) {

    $this->document->addStyle('css/styles/financeiro');
    $this->document->addScript('node_modules/currency.js/dist/currency.min');

    $data['produtos'] = $this->get_list();
    // echo "<pre>";
    //   print_r($data['produtos']);
    // echo "</pre>";
    // exit;

    $data['action_add_entrada'] = $this->url->link('financeiro/home/add_entrada');

    $data['modal_entrada'] = $this->load->view('financeiro/entrada', $data);


    $data['sidebar'] = $this->load->controller('common/sidebar', $data);
    $data['navbar'] = $this->load->controller('common/navbar', $data);
    $data['header'] = $this->load->controller('common/header', $data);
    $data['footer'] = $this->load->controller('common/footer', $data);

    $this->response->setOutput($this->load->view('financeiro/list', $data));
  }

  public function get_list() {
    $this->load->model('cardapio/categoria_cardapio');
    $this->load->model('cardapio/produto_cardapio');
    $this->load->model('estoque/produto');
    $categorias = $this->model_cardapio_categoria_cardapio->getAll(true);
    if (sizeof($categorias) > 0) {
      foreach($categorias as $key => $val) {
        $produtos = $this->model_cardapio_produto_cardapio->getByIdcategoria_cardapio($val['idcategoria_cardapio']);
        if (sizeof($produtos) > 0) {
          foreach($produtos as $i => $produto) {
            $status_produto_cardapio = true;

            $preco = Money::ofMinor($produto['preco'], 'BRL');
            $items_produto = json_decode($produto['produtos'], true);
            $obj_produto = array();
            if (sizeof($items_produto) > 0) {
              foreach($items_produto as $e => $info) {
                $produto_estoque = $this->model_estoque_produto->getById($e);
                $status = false;
                if ((float)$produto_estoque['qnt_atual'] >= (float)$info['qnt']) $status = true;

                $obj_produto[] = array(
                  'produto' => $produto_estoque,
                  'qnt' => $info['qnt'],
                  'status' => $status
                );

                if ($status == false) $status_produto_cardapio = false;
              }
            }

            $produtos[$i]['status_geral'] = $status_produto_cardapio;
            $produtos[$i]['info'] = $obj_produto;
            $produtos[$i]['preco'] = $preco->formatTo('pt_BR');
            $produtos[$i]['preco_safe'] = $produto['preco'];
          }
        }
        $categorias[$key]['produtos'] = $produtos;
        $categorias[$key]['link'] = $this->url->link('cardapio/home&idc=' . $val['idcategoria_cardapio']);
      }
    }
    return $categorias;
  }

  public function add_entrada() {
    if ($this->request->server['REQUEST_METHOD'] == 'POST') {

      $this->load->model('financeiro/financeiro');
      $errors = array();

      $total = Money::of(0, 'BRL');

      $financeiro = array(
        'idusuario' => $this->user->getIdusuario(),
        'joined' => date('Y-m-d H:i:s'),
        'tipo' => 'entrada',
        'valor' => '0',
        'descricao' => $this->request->post['descricao_geral'],
        'manual' => '1',
        'obj' => ''
      );

      $obj = array();
      $total = Money::of('0', 'BRL');

      $financeiro = $this->model_financeiro_financeiro->add($financeiro);
   
      // movimentacao estoque
      if (isset($this->request->post['chk_cardapio']) && $this->request->post['chk_cardapio'] == 'on') {
        $this->load->model('estoque/movimentacao');
        $this->load->model('estoque/produto');
        $this->load->model('cardapio/produto_cardapio');
  
        $movimentacao_estoque = array(
          'lancar' => 'saida',
          'descricao' => 'Movimentação financeira #' . $financeiro['idfinanceiro'],
          'produtos' => array(), // produtos do estoque, não do catalogo
          'obj' => array(), // quantidade de cada produto a sair do estoque (ex: 1l de vodka, 2un de agua)
          'idusuario' => $this->user->getIdusuario(),
          'joined' => date('Y-m-d H:i:s'),
          'integration' => '1'
        );

        $produtos_estoque = array(); $qnt_estoque = array();
      
        $produtos = $this->request->post['produtos'];
        if (sizeof($produtos) > 0) {
          foreach($produtos as $idproduto) {
            $produto_cardapio = $this->model_cardapio_produto_cardapio->getById($idproduto);
            if (!isset($this->request->post['qnt'][$idproduto])) {
              $errors[] = 'Você precisa especificar a quantidade de ' . $produto_cardapio['nome'];
              continue;
            }

            $qnt = (int)$this->request->post['qnt'][$idproduto];

            $preco = Money::ofMinor($produto_cardapio['preco'], 'BRL');
            $preco = $preco->multipliedBy($qnt);
            $total = $total->plus($preco);
            
            $ingredientes = json_decode($produto_cardapio['produtos'], true);
            if (sizeof($ingredientes) > 0) {
              foreach($ingredientes as $idp => $info) {
                $produto_estoque = $this->model_estoque_produto->getById($idp);
                $produto_estoque_old = $produto_estoque;

                $valor_diminuir_produto = (float)$info['qnt'] * $qnt;
                $produtos_estoque[] = $idp;
                $qnt_estoque[$idp] = $valor_diminuir_produto;

                $produto_estoque['qnt_atual'] = (float)$produto_estoque['qnt_atual'] - (float)$valor_diminuir_produto;

                $produto_estoque = $this->model_estoque_produto->save($produto_estoque);
                $this->log->save('movimentacao_edit_qnt_produto', array(
                  'new' => serialize($produto_estoque),
                  'old' => serialize($produto_estoque_old)
                ));

              }
            }

          }
        }

        $movimentacao_estoque['produtos'] = json_encode($produtos_estoque);
        $movimentacao_estoque['obj'] = json_encode($qnt_estoque);
  
        if (sizeof($errors) > 0) {
          $this->model_financeiro_financeiro->delete($financeiro['idfinanceiro']);
          $this->response->json(array('error' => true, 'errors' => $errors));
          exit;
        }
        
        $movimentacao_estoque = $this->model_estoque_movimentacao->add($movimentacao_estoque);
        $this->log->save('add_financeiro_movimentacao_estoque', array(
          'new' => serialize($movimentacao_estoque),
        ));

        
        $obj['cardapio'] = $movimentacao_estoque;
        

      }

      if (isset($this->request->post['chk_personalizadas']) && $this->request->post['chk_personalizadas'] == 'on') {
        $entradas_personalizadas = array();
        foreach ($this->request->post['entrada'] as $entrada) {
          $valor = str_replace('.', '', $entrada['valor']);
          $valor = str_replace(',', '.', $valor);
          $valor = Money::of($valor, 'BRL');

          $total = $total->plus($valor);

          $valor = $valor->getMinorAmount()->toInt();
          $entradas_personalizadas[] = array(
            'desc' => $entrada['desc'],
            'valor' => $valor
          );
        }
      }

      $obj['entradas'] = $entradas_personalizadas;
      $financeiro['valor'] = $total->getMinorAmount()->toInt();
      $financeiro['obj'] = json_encode($obj);

      $financeiro = $this->model_financeiro_financeiro->save($financeiro);
      $this->log->save('add_financeiro', array(
        'new' => serialize($financeiro),
      ));

      $this->response->json(array('error' => false));
    }
    
  }
}