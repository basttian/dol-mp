<?php
/* Copyright (C) 2023 SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    mp/class/actions_mp.class.php
 * \ingroup mp
 * \brief   Example hook overload.
 *
 * Put detailed description here.
 */
require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/mp/class/mppagos.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/mp/lib/mp.lib.php';

/**
 * Class ActionsMP
 */
class ActionsMP
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();


	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int		Priority of hook (50 is used if value is not defined)
	 */
	public $priority;


	/**
	 * Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Execute action
	 *
	 * @param	array			$parameters		Array of parameters
	 * @param	CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string			$action      	'add', 'update', 'view'
	 * @return	int         					<0 if KO,
	 *                           				=0 if OK but we want to process standard actions too,
	 *                            				>0 if OK and we want to replace standard actions.
	 */
	public function getNomUrl($parameters, &$object, &$action)
	{
		global $db, $langs, $conf, $user;
		$this->resprints = '';
		return 0;
	}

	/**
	 * Overloading the doActions function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs, $db;

		$error = 0; // Error counter
		$securekey = $object->id;


		//print_r($parameters); 
		//print_r($object); echo "action: " . $action;
		//print_r(dol_print_date($object->date_creation,'%Y-%m-%dT%H:%M:%S'));
		if (in_array($parameters['currentcontext'], array('invoicecard')) && $object->id > 0 ) {

			/**
			 * Session
			 */
			if ( ! session_id() ) @ session_start();
			if ( ! isset($_SESSION['FinalPaymentMp']) ) $_SESSION['FinalPaymentMp'] = $object->total_ttc;

			/**
			 * Factura
			 */
			$invoice = new Facture($db);
			$invoice->fetch($object->id);

			require_once DOL_DOCUMENT_ROOT.'/custom/mp/class/mppagos.class.php';
			$objectmp = new Mppagos($db);

			require_once DOL_DOCUMENT_ROOT.'/custom/mp/lib/mp/autoload.php';
			MercadoPago\SDK::setAccessToken($conf->global->MP_ACCESS_TOKEN);

			$preference = new MercadoPago\Preference();
			$item = new MercadoPago\Item();

			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$soc = new Societe($db);
			$soc->fetch($invoice->socid);
			
			$fdate = date_create(dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d %H:%M:%S', $tzoutput = 'auto', $outputlangs = '', $encodetooutput = false));
            $date_expire =  date_format($fdate,'Y-m-d\TH:i:s.vP');
		
			$expidatefrom = date_create(dol_print_date($invoice->date_creation, $format = '%Y-%m-%d %H:%M:%S', $tzoutput = 'auto', $outputlangs = '', $encodetooutput = false));
			$expire_date_from = date_format($expidatefrom,'Y-m-d\TH:i:s.vP');
		
			$expidateto = date_create(dol_print_date($invoice->date_lim_reglement, $format = '%Y-%m-%d %H:%M:%S', $tzoutput = 'auto', $outputlangs = '', $encodetooutput = false));
			$expire_date_to = date_format($expidateto,'Y-m-d\TH:i:s.vP');

			if ($conf->global->MP_SECURY_KEY_UNIQUE_URL) {
				$securekey = hash("sha1", $conf->global->MP_KEY_URL.'+'.$invoice->id, FALSE);
			}

			/**
			 * Hookeamos la accion confirm_valid desde facture card.php
			 */
			if($action == 'confirm_valid' || $action == 'create_link') {

			/**
			 * Verificamos si no se encuentra la preferencia ya creada, para evitar duplicados en nuestra referencia.
			 * Si se encuentra ya creada, solo actualizamos el precio conservvando el enlace ya creado.
			 * Enviamos los datos con el precio actualizado, por si hubo algun cambio en la factura.
			 */
			$verifierPreferenceId = new Mppagos($db);
			$value = $verifierPreferenceId->getPreferenceId($object->id);
			/**
			 * Buuscamos la preferencia
			 */
			$preferenc = @MercadoPago\Preference::get($value['idpreference']);
				if( !empty($value) && $preferenc->id == $value['idpreference']){ //return 1 la preferencia se encuentra creada..
					/**
				 	* Si la preferencia se encuentra ya creada, solo actualizamos el items..
					*  
				 	*/
					$arr = array();
					try {
						for ($i=0; $i < count($preferenc->items); $i++) { 
							$arr[] = array(
								"items" => array (
									array (
										'id' 			=> $invoice->id,
										'category_id' 	=> 'Facturacion',
										'title' 		=> 'MP_'.$soc->code_client.'_'.$invoice->id,
										'quantity' 		=> 1,
										'unit_price'	=> (float)$invoice->total_ttc
									)
								),
								"date_of_expiration" => $date_expire,//atributo obligatorio
							);
						};
						$mpCurl = curl_init();
						curl_setopt($mpCurl, CURLOPT_URL, "https://api.mercadopago.com/checkout/preferences/".$value['idpreference']);
						curl_setopt($mpCurl, CURLOPT_HEADER, 1);
						curl_setopt($mpCurl, CURLOPT_HTTPHEADER, array(
							"Authorization: Bearer ".$conf->global->MP_ACCESS_TOKEN,
							"Content-Type: application/json"
						));
						curl_setopt($mpCurl, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($mpCurl, CURLOPT_CUSTOMREQUEST, "PUT");
						curl_setopt($mpCurl, CURLOPT_POSTFIELDS, json_encode($arr));
						$resp = curl_exec($mpCurl);
						if(!curl_errno($mpCurl)){
							$info = curl_getinfo($mpCurl);
							dol_syslog( 'Took ' . $info['total_time'] . ' seconds to send a request to ' . $info['url']);
							if ($info['http_code']==200) {
								setEventMessages("Enlace de mercado pago actualizado con éxito $".price2num($invoice->total_ttc), '', $style = 'mesgs', $messagekey = 'mesgs');
							}
						} else {
							dol_syslog( 'Curl error: ' . curl_error($mpCurl));
						}
						curl_close($mpCurl);	
					} catch (Exception $e) {
						echo 'Excepción capturada: ',  $e->getMessage(), "\n";
					}
				}
				else
				{
				/**
				 * Si no se encuentra la preferencia .. 
				 * Creamos el enlace y apuntamos en nuetra tabla los datos, que luego actualizamos cuando se reciba el pago.
				 */

				if($invoice->total_ttc > 0  && (isset($conf->global->MP_CONFIG_BUTTON_LINKS)?1:0) || $securekey==GETPOST('s','aZ09') && (isset($conf->global->MP_CONFIG_BUTTON_LINKS)?0:1) ){//notas de credito `no programado` solo valores a cobrar que sean positivos

					$response_pref = 0;

					$item->id = $invoice->id;
					$item->category_id = "Facturacion";
					$item->title = "MP_".$soc->code_client.'_'.$invoice->id;//Titulo del producto: codigodel cliente + factura id
					$item->quantity = 1;
					$item->unit_price = $invoice->total_ttc;

					
					$preference->items = array($item);
					$preference->date_of_expiration = $date_expire;
					$preference->external_reference = $invoice->id;
					
					$preference->expiration_date_from = $expire_date_from;
					$preference->expiration_date_to = $expire_date_to;
					$preference->expires = true;
					$preference->back_urls = array(
						"success" => DOL_MAIN_URL_ROOT."/compta/facture/card.php?facid=".$object->id."&action=mpsuccess&s=".$securekey,
						"failure" => DOL_MAIN_URL_ROOT."/compta/facture/card.php?facid=".$object->id."&action=mpfailure&s=".$securekey,
						"pending" => DOL_MAIN_URL_ROOT."/compta/facture/card.php?facid=".$object->id."&action=mppending&s=".$securekey,
					);
					$preference->auto_return = "approved";

					$payer = new MercadoPago\Payer();
					$payer->email = $soc->email;
					$payer->name = $soc->name;
					$payer->name = $soc->name_alias;
					$payer->phone = array(
						'area_code' => '',
						'number' => $soc->phone
					);
					$payer->identification = array(
						"number" => $soc->idprof1,
						"type"	 => "Otro"
					);
					$payer->address = array(
						'zip_code' => '',
						'street_name' => $soc->address,
						'street_number' => ''
					);
					$preference->binary_mode = true; //Se aprueba el pago o rechaza al instante
					$preference->payer = $payer;

					/**
					 * payment_method_id
					 * Localización: bodyIdentificador del medio de pago.
					 *	Pix: Instant digital payment method used in Brazil.
					 *	Account_money: When the payment is debited directly from a Mercado Pago account.
					 *	Debin_transfer: Digital payment method used in Argentina that immediately debits an amount from an account, requesting prior authorization.
					 *	Ted: It is the Electronic Transfer Available payment, used in Brazil, that has fees to be used. The payment is made the same day of the transaction, but for this it is necessary to make the transfer within the stipulated period;
					 *	Cvu: Payment method used in Argentina.
					 */

					/**
					 * payment_type_id
					 *	
					 *	Localización: bodyTipo de medio de pago
					 *	ticket: Printed ticket
					 *	atm: Payment by ATM
					 *	credit_card: Payment by credit card
					 *	debit_card: Payment by debit card
					 *	prepaid_card: Payment by prepaid card
					 */

					 $preference->payment_methods = array(
						"default_payment_method_id" =>[],
						"excluded_payment_types" => array(
							array("id" => "ticket")
						),
						"default_payment_method_id" => "", //Forma de pago sugerida
						"installments" => 6, //Max numero de cuotas
						"default_installments" => 6, //Preferencia de cuotas
					);
					
					$response_pref = $preference->save();

					if($response_pref){
				
						//Creamos el enlace para el pago. 
						$objcreateLink = new Link($db);
						$objcreateLink->url = $_SERVER['PHP_SELF']."?facid=".$invoice->id."&action=mperrorurl";//#1 OBSERVAR
						if ($conf->global->MP_CREDENTIALS) {
							$objcreateLink->url = $preference->init_point;
						}else {
							$objcreateLink->url = $preference->sandbox_init_point;
						}
						$objcreateLink->label = "MP_".$soc->code_client.'_'.$invoice->id." <span class='fas fa-external-link-alt'></span>";
						$objcreateLink->objecttype = 'facture';
						$objcreateLink->objectid = $invoice->id;
						$objcreateLink->create($user);

						//Almacenamos internamente los datos basicos sin el pago y sin orden
						
						$objectmp->ref = $invoice->id;
						$objectmp->label = "MP_".$soc->code_client.'_'.$invoice->id;
						$objectmp->fk_facture = $invoice->id;
						$objectmp->fk_soc = $invoice->socid;
						$objectmp->status = 0;
						$objectmp->description = 'Resta pagar esta factura en mp.';
						$objectmp->mp_preference_id = !empty($preference->id)?$preference->id:'-';
						$objectmp->mp_external_reference = !empty($preference->external_reference)?$preference->external_reference:'-';
						$objectmp->mp_site_id = !empty($preference->site_id)?$preference->site_id:'-';
						$objectmp->mp_status = '-';
						$objectmp->mp_payment_id = '-';
						$objectmp->mp_payment_type = '-';
						$objectmp->mp_merchant_order_id = '-';
						//$objectmp->mp_collection_id = '-';
						//$objectmp->mp_collection_status = '-';
						//$objectmp->mp_processing_mode = '-';
						//$objectmp->mp_merchant_account_id = '-';

						$mp_id = $objectmp->create($user,1);
						if ($mp_id < 0) {
							$error++;
							$this->errors[] = "Error!! Create object in dolibar table mp pagos.";
						}
					}else{
						$error++;
						$this->errors[] = "ERROR!! No se creo el enlace de cobro en Mercado Pago.";
					}

				}	

				}
			}

			/**
			 * Si se elimina o modifica la factura, se elimina el enlace.
			 * Si se clasifica abandonado action canceled, no se realiza ninguna accion.
			 * 
			 * $action == 'confirm_modif' || $action == 'deletepayment'
			 * Eliminamos el enlace y el registro solo si se borra la factura.
			 */
			if( $action == 'delete' ) {

				//Elimino la referencia de la tabla mppagos
				require_once DOL_DOCUMENT_ROOT.'/custom/mp/class/mppagos.class.php';
				$objectmp = new Mppagos($db);
				$response = $objectmp->doDeleteRowFromFactureID((int)$object->id);
				if($response > 0){
					//Elimino el link
					$objcreateLink = new Link($db);
					$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."links WHERE objectid = ".(int)$object->id;
					$resql = $db->query($sql);
					if ($resql) {
						$num = $db->num_rows($resql);
						$i = 0;
						if ($num)
						{
							while ($i < $num)
							{
								$obj = $db->fetch_object($resql);
								if ($obj) {
									$objcreateLink->id = (int)$obj->rowid;
									$objcreateLink->delete($user);
								}
							$i++;
							}
						}
					}else{
						$error++;
						$this->errors[] = "Error!! Create Pay Link.";
					}
				}else{
					$error++;
					$this->errors[] = "Error!! Save data in dolibar mp pagos.";
				}

				
			}

			/**
			 * Si no se crea la url de mercado pago, se realiza el enlace por defecto ver `$objcreateLink->url`.
			 * Solucion recrear el enlace desde el frontend. Modificando la factura y volviendo a confirmar.
			 */
			if($action == 'mperrorurl') {
				setEventMessages("Error al crear la url de pago en mercado pago.", null, 'errors');
			}

			
			/**
			 * Acciones devueltas desde mercado pago.
			 * 
			 */
			$s=GETPOST('s','aZ09');
			if($action == 'mpsuccess' && $s == $securekey){

				/**
				 * Datos tomados de la respuesta `url`
				 */
				$payment_id = GETPOST('payment_id','alpha');
				$status = GETPOST('status','alpha');
				$external_reference = GETPOST('external_reference','aZ09');
				$payment_type = GETPOST('payment_type','aZ09');
				$merchant_order_id = GETPOST('merchant_order_id','aZ09');
				$preference_id = GETPOST('preference_id','aZ09');
				$site_id = GETPOST('site_id','aZ09');
				
				$arrData = array(
					'payment_id' => $payment_id,
					'status' =>$status,
					'external_reference' => $external_reference, //Le voy a pasar este valor de referencia al Id de mi factura
					'payment_type' => $payment_type,
					'merchant_order_id'=> $merchant_order_id,
					'preference_id' => $preference_id,
					'site_id' => $site_id
				);
				
				$returnpago = aprobarPagosEnElSistema(json_encode($arrData));
				if($returnpago){
					unset($_SESSION["FinalPaymentMp"]);
				}

			}

			if($action == 'mppending' && $s == $securekey){
				unset($_SESSION["FinalPaymentMp"]);
				setEventMessages("Pendiente de pago.", null, 'warnings');
			}

			if($action == 'mpfailure' && $s == $securekey){
				unset($_SESSION["FinalPaymentMp"]);
			}

		}

		if (!$error) {
			$this->results = array('response' => 1);
			$this->resprints = "";
			return 0; // or return 1 to replace standard code
		} else {
			$this->errors[] = '';
			return -1;
		}

	}

	/* Add here any other hooked methods... */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
	    global $db, $conf, $user, $langs;
	    $error = 0; // Error counter

		$securekey = $object->id;

		if ($conf->global->MP_SECURY_KEY_UNIQUE_URL) {
			$securekey = hash("sha1", $conf->global->MP_KEY_URL.'+'.$object->id, FALSE);
		}

		$obj_mercadopago = new Mppagos($db);
		$preferencia = $obj_mercadopago->ifExist($object->id);

	    /* print_r($parameters); print_r($object); echo "action: " . $action; */
	    if (in_array($parameters['currentcontext'], array('invoicecard'))) {	    // do something only for the context 'somecontext1' or 'somecontext2'

			if($user->rights->mp->mp->write && empty($preferencia) ){
			if(isset($conf->global->MP_CONFIG_BUTTON_LINKS)?0:1 && $object->statut == Facture::STATUS_VALIDATED && $object->type == Facture::TYPE_STANDARD ){
				print '<span id="btn_create_link" class="butAction" title="'.$langs->trans('btn_create_link_mp').'" >
				<span id="btn-loding" class="fa fa-link" style="color: white"></span>&nbsp;&nbsp;'.$langs->trans('btn_create_link_mp').'</span>';
				/** JS */
				?>
				<script>
					jQuery(document).ready(function() {

						$('#btn_create_link').click(function(e){
							e.preventDefault();

							$('#btn-loding').addClass('fa-spin');
							$.post("<?php echo DOL_URL_ROOT;?>/compta/facture/card.php?facid=<?php echo $object->id;?>&action=create_link&s=<?php echo $securekey;?>&token=<?php echo currentToken(); ?>",
							function( data ) {
								console.log("Link created..");
							})
							.done(function(data) {
								$('#btn-loding').removeClass('fa-spin');
								location.reload();
							})
							.fail(function(e) {
								console.error(e);
							})
							.always(function() {
								$('#btn-loding').removeClass('fa-spin');
							});

						});

					});
				</script>
				<?php
			}
			}	

			if (!$error) {
				return 0; // or return 1 to replace standard code
			} else {
				$this->errors[] = 'Error message';
				return -1;
			}
	
		}

	}


	/**END*/
}
