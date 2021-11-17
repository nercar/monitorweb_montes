<?php
	/**
	* Permite obtener los datos de la base de datos y retornarlos
	* en modo json o array
	*/

	try {
		date_default_timezone_set('America/Bogota');
		// Se capturan las opciones por Post
		$opcion = (isset($_POST["opcion"])) ? $_POST["opcion"] : "";
		$fecha  = (isset($_POST["fecha"]) ) ? $_POST["fecha"]  : date("Y-m-d");
		$hora   = (isset($_POST["hora"])  ) ? $_POST["hora"]   : date("H:i:s");

		// id para los filtros en las consultas
		$idpara = (isset($_POST["idpara"])) ? $_POST["idpara"] : '';

		// Se establece la conexion con la BBDD
		$params = parse_ini_file('../dist/config.ini');

		if ($params === false) {
			// exeption leyen archivo config
			throw new \Exception("Error reading database configuration file");
		}

		// connect to the sql server database
		if($params['instance']!='') {
			$conStr = sprintf("sqlsrv:Server=%s\%s;",
				$params['host_sql'],
				$params['instance']);
		} else {
			$conStr = sprintf("sqlsrv:Server=%s,%d;",
				$params['host_sql'],
				$params['port_sql']);
		}

		$connec = new \PDO($conStr, $params['user_sql'], $params['password_sql']);

		$moneda      = $params['moneda'];
		$simbolo     = $params['simbolo'];
		$fecinibimas = $params['fecinibimas'];
		$host_ppl    = $params['host_ppl'];

		switch ($opcion) {
			case 'hora_srv':
				echo json_encode('1¬' . $hora);
				break;

			case 'consultarTBLDivisas':
				$sql = "SELECT COALESCE(Object_Id('BDES.dbo.ESFormasPago_FactorC'), 0) AS existe";
				$sql = $connec->query($sql);
				$row = $sql->fetch(\PDO::FETCH_ASSOC);
				if($row['existe']>0){
					echo 1;
				} else {
					echo 0;
				}
				break;

			case 'iniciar_sesion':
				extract($_POST);
				if(empty($tusuario) || empty($tclave)){
					header("Location: " . $idpara);
					break;
				}

				$sql = "SELECT login, descripcion, codusuario, password AS clave, activo
						FROM BDES.dbo.ESUsuarios WHERE LOWER(login)=LOWER('$tusuario') 
						AND password = '$tclave'";

				$sql = $connec->query($sql);
				$row = $sql->fetch(\PDO::FETCH_ASSOC);

				if($row) {
					if($row['activo']!=1) {
						session_destroy();
						session_commit();
						session_start();
						session_id($_SESSION['id']);
						session_destroy();
						session_commit();
						session_start();
						$_SESSION['error'] = 2;
						header("Location: " . $idpara);
					} else {
						session_start();
						$_SESSION['id']         = session_id();
						$_SESSION['url']        = $idpara;
						$_SESSION['usuario']    = strtolower($row['login']);
						$_SESSION['nomusuario'] = ucwords(strtolower($row['descripcion']));
						$_SESSION['error']      = 0;
						header("Location: " . $idpara . "inicio.php");
					}
				} else {
					session_start();
					session_id($_SESSION['id']);
					session_destroy();
					session_commit();
					session_start();
					$_SESSION['error'] = 1;
					header("Location: " . $idpara);
				}
				break;

			case 'cerrar_sesion':
				session_start();
				session_id($_SESSION['id']);
				session_destroy();
				session_commit();
				header("Location: " . $_SESSION['url']);
				exit();
				break;

			case 'calDivisas':
				$sql = "SELECT simbolo, FACTOR, MULTIPLICA FROM BDES.dbo.ESFormasPago_FactorC WHERE CODIGO != 63";

				$sql = $connec->query($sql);

				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datos[] = [
						'simbolo'    => $row['simbolo'],
						'factor'     => $row['FACTOR']*1,
						'multiplica' => $row['MULTIPLICA']*1,
					];
				}
				
				echo json_encode($datos);
				break;
			
			case 'cedulasid':
				$sql = "SELECT id, descripcion, predeterminado 
						FROM BDES.dbo.ESCedulasId 
						ORDER BY predeterminado DESC";
			
				$sql = $connec->query($sql);
			
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datos[] = [
						'id'             => $row['id'],
						'descripcion'    => $row['descripcion'],
						'predeterminado' => $row['predeterminado'],
					];
				}
				
				echo json_encode($datos);
				break;
			
			case 'consultarClte':
				$sql = "SELECT RIF, RAZON, DIRECCION, EMAIL, TELEFONO
						FROM BDES_POS.dbo.ESCLIENTESPOS
						WHERE RIF = '$idpara'
						AND activo = 1";

				$sql = $connec->query($sql);
			
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datos[] = [
						'rif'       => $row['RIF'],
						'razon'     => $row['RAZON'],
						'telefono'  => $row['TELEFONO'],
						'email'     => $row['EMAIL'],
						'direccion' => $row['DIRECCION'],
					];
				}
				
				echo json_encode($datos);
				break;
			
			case 'calTotalesDespacho':
				$sql = "SELECT SUM(CANTIDAD) AS CANTIDAD, SUM(CANTIDAD*(PRECIOOFERTA*(1+(PORC/100)))) AS MONTO, COUNT(IDTR) AS ITEMS
				FROM BDES_POS.dbo.DBVENTAS_TMP_DET WHERE IDTR = $idpara AND IMAI = 1";
			
				$sql = $connec->query($sql);
			
				$row = $sql->fetch(\PDO::FETCH_ASSOC);
				$decimales = $row['CANTIDAD'] - intval($row['CANTIDAD']);
				$enteros = $row['CANTIDAD'] - $decimales;
				$cantidad = number_format($enteros, 0) . '.<sub>'.substr(number_format($decimales, 3), 2).'</sub>';
				$datos = [
					'cantidad' => $cantidad,
					'monto'    => number_format($row['MONTO']*1, 2),
					'items'    => $row['ITEMS']*1,
				];
				
				echo json_encode($datos);
				break;
	
			case 'consultaDispo':
				extract($_POST);
				if($buscaren==2) {
					$sql = "SELECT CODIGO 
							FROM BDES.dbo.ESGrupos
							WHERE DESCRIPCION LIKE '%$idpara%'";
					$sql = $connec->query($sql);
					// Se prepara el array para almacenar los datos obtenidos
					$grupos = '';
					while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
						$grupos.= $row['CODIGO'] .',';
					}
					$grupos = ($grupos!='') ? substr($grupos, 0, -1) : '99999';

					$sql = "SELECT CODIGO 
							FROM BDES.dbo.ESSubgrupos
							WHERE DESCRIPCION LIKE '%$idpara%'";
					$sql = $connec->query($sql);
					// Se prepara el array para almacenar los datos obtenidos
					$subgrupos = '';
					while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
						$subgrupos.= $row['CODIGO'] .',';
					}
					$subgrupos = ($subgrupos!='') ? substr($subgrupos, 0, -1) : '99999';
				}

				if($host_ppl!='') {
					$vendido = '(SELECT (COALESCE((SELECT SUM(CANTIDAD) FROM ['.$host_ppl.'].BDES_POS.dbo.ESVENTASPOS_DET
								WHERE CAST(FECHA AS DATE) = CAST(GETDATE() AS DATE) AND ARTICULO = d.material),0)+
								COALESCE((SELECT SUM(CANTIDAD) FROM BDES_POS.dbo.ESVENTASPOS_DET
								WHERE CAST(FECHA AS DATE) = CAST(GETDATE() AS DATE)
								AND ARTICULO = d.material),0)
								)) AS cantvendida,';
				} else {
					$vendido = 'COALESCE((SELECT SUM(CANTIDAD) FROM BDES_POS.dbo.ESVENTASPOS_DET
								WHERE CAST(FECHA AS DATE) = CAST(GETDATE() AS DATE)
								AND ARTICULO = d.material), 0) AS cantvendida,';
				}

				$sql="SELECT
						d.material, COALESCE(bar.barra, CAST(d.material AS VARCHAR)) AS barra,
						a.descripcion, (CASE WHEN a.tipoarticulo != 0 THEN 1 ELSE 0 END) AS pesado,
						COALESCE(SUM(d.ExistLocal), 0) AS existlocal," . $vendido . "
						a.precio1 AS base, a.impuesto, a.costo,
						(CASE WHEN CAST(GETDATE() AS DATE) 
							BETWEEN CAST(a.fechainicio AS DATE) AND CAST(a.fechafinal AS DATE)
						THEN a.preciooferta ELSE 0 END) AS oferta
					FROM (SELECT articulo AS material, SUM(cantidad-usada) AS existlocal 
							FROM BDES.dbo.BIKardexExistencias
							WHERE almacen = 1201
							GROUP BY articulo) AS d
						INNER JOIN BDES.dbo.ESARTICULOS a ON a.codigo = d.material AND a.activo = 1
						LEFT JOIN (SELECT escodigo, MAX(DISTINCT barra) AS barra
							FROM BDES.dbo.ESCodigos
							WHERE CAST(escodigo AS VARCHAR) != barra 
							GROUP BY escodigo) AS bar ON bar.escodigo = a.codigo";

				if($buscaren==1) {
					$sql.= " WHERE (a.codigo LIKE '%$idpara%'
								OR LOWER(a.descripcion) LIKE '%$idpara%'
								OR bar.barra LIKE '%$idpara%')";
				} else {
					$sql.= " WHERE a.grupo IN ($grupos) OR a.subgrupo IN ($subgrupos)";
				}
					
				$sql.= " GROUP BY d.material, a.descripcion, bar.barra, a.tipoarticulo,
					a.precio1, a.impuesto, a.preciooferta, a.fechainicio, a.fechafinal, a.costo
					HAVING (SUM(existlocal) > 0)
					ORDER BY a.descripcion ASC";

				// Se ejecuta la consulta en la BBDD
				$sql = $connec->query($sql);

				// Se prepara el array para almacenar los datos obtenidos
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$precio = $row['base'] * ( 1 + ( $row['impuesto'] / 100 ) );
					if($row['oferta']>0) {
						$precio = $row['oferta'] * ( 1 + ( $row['impuesto'] / 100 ) );
					}
					
					$existlocal = ($row['existlocal'] - $row['cantvendida']);
					if($precio>0 && $existlocal>0) {
						$txt = '<button type="button" title="Agregar Artículo" onclick="' .
									" addarticulo('" . $row['material'] . "','" . $row['barra'] . 
										"','" . $row['descripcion'] . "'," . round($precio, 2)*1 .
										"," . $existlocal . "," . $row['pesado']*1 . 
										"," . round($row['base']*1,2) . "," . round($row['impuesto']*1,2) .
										"," . round($row['costo']*1,2) . ")" .
									'" class="btn btn-link m-0 p-0 text-left font-weight-bold" ' .
									' style="white-space: normal; line-height: 1;">' . ucwords($row['descripcion']) .
								'</button>';
						$datos[] = [
							'material'    => $row['material'],
							'barra'       => '<span title="' . $row['material'] . '">' . $row['barra'] . '</span>',
							'descripcion' => $txt,
							'precio'      => number_format($precio, 2),
							'existlocal'  => number_format($existlocal, 2),
							'pesado'      => $row['pesado']*1,
						];
					}
				}
				echo json_encode($datos);
				break;

			case 'guardarPrefactura':
				extract($_POST);
				$detalle = json_decode($detalle);

				$sql = "SELECT (ValorCorrelativo + 1) AS ValorCorrelativo
						FROM BDES_POS.dbo.ESCORRELATIVOS
						WHERE Correlativo = 'CompraEsperaRem'";

				$sql = $connec->query($sql);
				$idtr = $sql->fetch(\PDO::FETCH_ASSOC);
				$idtr = $idtr['ValorCorrelativo'];

				$sql = "UPDATE BDES_POS.dbo.ESCORRELATIVOS
						SET ValorCorrelativo = $idtr
						WHERE Correlativo = 'CompraEsperaRem'";
			
				$sql = $connec->query($sql);

				if($sql) {
					$sql = "INSERT INTO BDES_POS.dbo.DBVENTAS_TMP
								(IDTR, IDCLIENTE, ACTIVA, FECHAHORA, CAJA, RAZON, DIRECCION, LIMITE, CREADO_POR)
								VALUES($idtr, '$cedulasid'+'$idclte', 1, CURRENT_TIMESTAMP, 999,
									  '$nomclte', '$dirclte', 0, '$usuario')";

					$sql = $connec->query($sql);

					if($sql) {
						$sql = "INSERT INTO BDES_POS.dbo.DBVENTAS_TMP_DET
								(IDTR, LINEA, ARTICULO, BARRA, PRECIO, COSTO, CANTIDAD, PEDIDO,
								SUBTOTAL, IMPUESTO, PORC, PRECIOREAL, PROMO, PROMODSCTO, PRECIOOFERTA)
								VALUES";

						$i = 1;
						foreach ($detalle as $value) {
							$precio = $value->precio/(1+($value->impuesto/100));
							$sql .= "($idtr, $i, '$value->codigo', '$value->barra', $precio, 
									  $value->costo, $value->cantidad, $value->cantidad, 0, 0, $value->impuesto,
									  $value->precioreal, 0, 0, $precio),";
							$i++;
						}

						$sql = $connec->query(substr($sql, 0, -1));
						if(!$sql) {
							print_r( $connec->errorInfo() );
						}
					} else {
						print_r( $connec->errorInfo() );
					}

					$sql = "SELECT COUNT(*) AS cuenta FROM BDES_POS.dbo.ESCLIENTESPOS
							WHERE RIF = '$cedulasid'+'$idclte'
							AND ACTIVO = 1";

					$sql = $connec->query($sql);
					$sql = $sql->fetch(\PDO::FETCH_ASSOC);

					if($sql['cuenta']==0) {

						$sql = "SELECT (ValorCorrelativo + 1) AS ValorCorrelativo
								FROM BDES_POS.dbo.ESCORRELATIVOS
								WHERE Correlativo = 'ClientePos'";

						$sql = $connec->query($sql);
						$codclte = $sql->fetch(\PDO::FETCH_ASSOC);
						$codclte = $codclte['ValorCorrelativo'];

						$sql = "UPDATE BDES.dbo.ESCorrelativos
								SET ValorCorrelativo = $codclte
								WHERE Correlativo = 'Cliente'";
					
						$sql = $connec->query($sql);

						if($sql) {
							$sql = "INSERT INTO BDES_POS.dbo.ESCLIENTESPOS
									(RIF, RAZON, DIRECCION, ACTIVO, IDTR, ACTUALIZO, EMAIL, TELEFONO)
									VALUES('$cedulasid'+'$idclte', '$nomclte', '$dirclte', 1, $codclte,
									1, '$emailclte', '$telclte')";
							$sql = $connec->query($sql);
						} else {
							print_r( $connec->errorInfo() );
						}
					} else {
						$sql = "UPDATE BDES_POS.dbo.ESCLIENTESPOS
								SET RAZON = '$nomclte', DIRECCION = '$dirclte', ACTIVO = 1,
								EMAIL = '$emailclte', TELEFONO = '$telclte'
								WHERE RIF = '$cedulasid'+'$idclte'";
						$sql = $connec->query($sql);
					}
				} else {
					print_r( $connec->errorInfo() );
				}

				if($sql) {
					echo $idtr;
				} else {
					echo 0;
				}
				break;
				
			case 'agregaArtDesp':
				$idpara = explode('¬', $idpara);
			
				$sql = "INSERT INTO BDES_POS.dbo.DBVENTAS_TMP_DET
						(IDTR, LINEA, ARTICULO, BARRA, PRECIO, COSTO, CANTIDAD, PEDIDO,
						SUBTOTAL, IMPUESTO, PORC, PRECIOREAL, PROMO, PROMODSCTO, IMAI)
						VALUES
						($idpara[0], $idpara[1]+1, $idpara[2], '$idpara[3]', $idpara[4], 
						$idpara[5], 1, 1, 0, 0, $idpara[6], $idpara[7], 0, 0, 1)";

				$sql = $connec->query($sql);

				if($sql) {
					echo 1;
				} else {
					echo 0;
					print_r( $connec->errorInfo() );
				}
				break;
				
			case 'delArtDesp':
				$idpara = explode('¬', $idpara);
				$sql = "DELETE FROM BDES_POS.dbo.DBVENTAS_TMP_DET WHERE IDTR = $idpara[0] AND ARTICULO = $idpara[1]";
			
				$sql = $connec->query($sql);
			
				if($sql) {
					echo 1;
				} else {
					echo 0;
					print_r( $connec->errorInfo() );
				}
				break;
			
			case 'crearTemporalCab':
				extract($_POST);
				$sql = "INSERT INTO BDES_POS.dbo.DB_TMP_VENTAS
							(FECHAHORA, RIF, NOMBRE, TELEFONO, CORREO, DIRECCION, ACTIVA)
							VALUES
							(CURRENT_TIMESTAMP, '$idclte', '$nomcte', '$telcte', '$emacte', '$dircte', 1)";

				$sql = $connec->query($sql);
				if($sql) {
					$sql  ="SELECT IDENT_CURRENT('BDES_POS.dbo.DB_TMP_VENTAS') as idtr";
					$sql  = $connec->query($sql);
					$sql  = $sql->fetch(\PDO::FETCH_ASSOC);
					$idtr = $sql['idtr'];
				} 

				echo $idtr;
				break;
				
			case 'crearTemporalDet':
				extract($_POST);
				$sql = "INSERT INTO BDES_POS.dbo.DB_TMP_VENTAS_DET
						(ID, ARTICULO, BARRA, PRECIO, COSTO, CANTIDAD, PORC, PRECIOREAL, ACTIVA)
						VALUES
						($idtemp, $codart, '$barart', $pvpart, $cosart, $canart, $impart, $preart, 1)";

				$sql = $connec->query($sql);
				if($sql)
					echo 1;
				else {
					echo 0;
					print_r( $connec->errorInfo() );
				}
				break;

			case 'modCantidadTmp':
				$params = explode('¬', $idpara);
				$sql = "UPDATE BDES_POS.dbo.DB_TMP_VENTAS_DET SET
						CANTIDAD = $params[2]
						WHERE ID = $params[0] AND ARTICULO = $params[1]";

				$sql = $connec->query($sql);
				if($sql)
					echo 1;
				else {
					echo 0;
					print_r( $connec->errorInfo() );
				}
				break;
				
			case 'delArtTmp':
				$idpara = explode('¬', $idpara);
				$sql = "DELETE FROM BDES_POS.dbo.DB_TMP_VENTAS_DET WHERE ID = $idpara[0] AND ARTICULO = $idpara[1]";
			
				$sql = $connec->query($sql);
			
				if($sql) {
					echo 1;
				} else {
					echo 0;
					print_r( $connec->errorInfo() );
				}
				break;
			
			case 'buscarTemporal':
				$sql = "SELECT ID AS nrodoc, CONVERT(VARCHAR, FECHAHORA, 103) AS FECHA,
							CONVERT(VARCHAR(5), FECHAHORA, 108) AS HORA,
							RIF, NOMBRE, TELEFONO, CORREO, DIRECCION
						FROM BDES_POS.dbo.DB_TMP_VENTAS
						WHERE RIF = '$idpara' AND ACTIVA = 1";
			
				$sql = $connec->query($sql);
				$cabecera = $sql->fetch(\PDO::FETCH_ASSOC);
				if( $cabecera ) {
					$recupera = 1;
					$sql = "SELECT det.*, art.descripcion AS DESCRIPCION,
								(CASE WHEN art.tipoarticulo != 0 THEN 1 ELSE 0 END) AS PESADO,
								COALESCE((SELECT SUM(cantidad) 
									FROM BDES.dbo.BIKardexExistencias
									WHERE articulo = det.ARTICULO), 0) AS EXISTLOCAL,
								COALESCE((SELECT SUM(CANTIDAD) FROM BDES_POS.dbo.ESVENTASPOS_DET
									WHERE CAST(FECHA AS DATE) = CAST(GETDATE() AS DATE)
									AND ARTICULO = det.ARTICULO), 0) AS CANTVENDIDA
							FROM BDES_POS.dbo.DB_TMP_VENTAS_DET det
							INNER JOIN BDES.dbo.ESARTICULOS art ON art.codigo = det.ARTICULO 
							WHERE ACTIVA = 1 AND ID = " . $cabecera['nrodoc'];
					$sql = $connec->query($sql);
					$detalle = $sql->fetchAll(\PDO::FETCH_ASSOC);
				} else {
					$recupera = 0;
					$cabecera = [];
					$detalle = [];
				}

				echo json_encode(array('recupera' => $recupera, 'cabecera' => $cabecera, 'detalle' => $detalle));
				break;
			
			case 'eliminarTemporal':
				$sql = "UPDATE BDES_POS.dbo.DB_TMP_VENTAS     SET ACTIVA = 0 WHERE ID = $idpara; 
						UPDATE BDES_POS.dbo.DB_TMP_VENTAS_DET SET ACTIVA = 0 WHERE ID = $idpara";

				$sql = $connec->query($sql);
				if($sql) {
					echo '1-'.$idpara;
				} else {
					echo 0;
					print_r( $connec->errorInfo() );
				}
				break;

			case 'despachosWebpendientes':
				if($idpara!='') {
					// Se prepara la consulta para los articulos para paginas web
					$sql = "SELECT cab.IDTR, 
							(CONVERT(VARCHAR(10), cab.FECHAHORA, 105)+' '+CONVERT(VARCHAR(5), cab.FECHAHORA, 108)) AS FECHAHORA,
							cab.IDCLIENTE, cab.RAZON, cab.GRUPOC, cli.TELEFONO, cab.mensaje, cab.DIRECCION,
							cab.montodomicilio, cab.descuentos
							FROM BDES_POS.dbo.DBVENTAS_TMP cab
							LEFT OUTER JOIN BDES_POS.dbo.ESCLIENTESPOS cli ON cli.RIF = cab.IDCLIENTE
							WHERE  cab.GRUPOC <= 1 AND cab.IDTR = $idpara";

					// Se ejecuta la consulta en la BBDD
					$sql = $connec->query($sql);
					if(!$sql) print_r($connec->errorInfo());

					// Se prepara el array para almacenar los datos obtenidos
					$datos = [];
					while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
						$datos[] = [
							'nrodoc'    => $row['IDTR'],
							'fecha'     => date('d-m-Y H:i', strtotime($row['FECHAHORA'])),
							'idcliente' => $row['IDCLIENTE'],
							'nombre'    => $row['RAZON'],
							'activa'    => $row['GRUPOC'],
							'telefono'  => $row['TELEFONO'],
							'mensaje'   => $row['mensaje'],
							'direccion' => $row['DIRECCION'],
							'domicilios'=> $row['montodomicilio'],
							'descuentos'=> $row['descuentos']
						];
					}
				} else {
					$datos = [];
				}

				echo json_encode(array('data' => $datos));
				break;

			case 'detalleDespachoweb':
				if($idpara!='') {
					$sql = "SELECT IDTR, ARTICULO, BARRA, IMAI,
								art.descripcion AS NOMBRE,
								(CASE WHEN art.tipoarticulo != 0 THEN 1 ELSE 0 END) AS pesado,
								ROUND(det.PRECIOOFERTA+(det.PRECIOOFERTA*PORC/100), 2) AS PRECIO,
								SUM(CANTIDAD) AS CANTIDAD
							FROM BDES_POS.dbo.DBVENTAS_TMP_DET AS det
							INNER JOIN BDES.dbo.ESARTICULOS AS art ON art.codigo = det.ARTICULO
							WHERE IDTR = $idpara
							GROUP BY IDTR, ARTICULO, BARRA, IMAI, art.descripcion, art.tipoarticulo,
								det.PRECIOOFERTA, PORC, det.LINEA
							ORDER BY det.LINEA";

					$sql = $connec->query($sql);
					if(!$sql) print_r($connec->errorInfo());

					$datos = [];
					while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
						$datos[]=[
							'nrodoc'   => $row['IDTR'],
							'codigo'   => $row['ARTICULO'],
							'barra'    => $row['BARRA'],
							'imai'     => $row['IMAI'],
							'nombre'   => $row['NOMBRE'],
							'precio'   => $row['PRECIO'],
							'cantidad' => ($row['pesado']==1 ? $row['CANTIDAD']*1:round($row['CANTIDAD']*1, 0)),
							'pesado'   => $row['pesado'],
						];
					}
				} else {
					$datos = [];
				}

				echo json_encode($datos);
				break;

			case 'marcarCabeceraweb':
				// se extraen los valores del parametro idpara
				$params = explode('¬', $idpara);
				$usuario = "'" . $params[2] . "'";
				$fpickin = 'CURRENT_TIMESTAMP';
				if($params[1]==0) {
					$usuario = 'null';
					$fpickin = 'null';
				}
				// Se modifica el valor para indicar si se envia o no al excel
				$sql = "UPDATE BDES_POS.dbo.DBVENTAS_TMP SET
							GRUPOC = $params[1],
							PICKING_POR = $usuario,
							FECHA_PICKING = $fpickin
						WHERE IDTR = $params[0]";

				$sql = $connec->query($sql);

				if($sql) {
					echo '1';
				} else {
					echo '0';
					print_r( $connec->errorInfo() );
				}
				break;

			case 'marcarDetalleweb':
				// se extraen los valores del parametro idpara
				$params = explode('¬', $idpara);
				// Se modifica el valor para indicar si se envia o no al excel
				$sql = "UPDATE BDES_POS.dbo.DBVENTAS_TMP_DET SET 
						IMAI = $params[2]
						WHERE IDTR = $params[0] AND ARTICULO = $params[1]";

				$sql = $connec->query($sql);

				if($sql) {
					echo '1';
				} else {
					echo '0';
					print_r( $connec->errorInfo() );
				}
				break;

			case 'modCantidadweb':
				// se extraen los valores del parametro idpara
				$params = explode('¬', $idpara);
				// Se modifica el valor para indicar si se envia o no al excel
				$sql = "UPDATE BDES_POS.dbo.DBVENTAS_TMP_DET SET 
						CANTIDAD = $params[2]
						WHERE IDTR = $params[0] AND ARTICULO = $params[1]";

				$sql = $connec->query($sql);

				if($sql) {
					echo '1';
				} else {
					echo '0';
					print_r( $connec->errorInfo() );
				}
				break;

			case 'procDctoweb':
				// se extraen los valores del parametro idpara
				$params = explode('¬', $idpara);
				// Se modifica el valor para indicar si se envia o no al excel
				$sql = "UPDATE BDES_POS.dbo.DBVENTAS_TMP SET
						GRUPOC = 2, FECHA_PROCESADO = CURRENT_TIMESTAMP,
						PROCESADO_POR = '$params[1]'
						WHERE IDTR = $params[0]";

				$sql = $connec->query($sql);

				if($sql) {
					$sql = "INSERT INTO BDES_POS.dbo.ESVENTAS_TMP
								(IDTR, IDCLIENTE, ACTIVA, LIMITE, FECHAHORA, SUSPENDIDO,
								 PERMITEREG, CAJA, RAZON, DIRECCION, SODEXOACTIVO, pais,
								 estado, ciudad, tipoc, Codigoc, NDE, GRUPOC)
							SELECT
								IDTR, IDCLIENTE, ACTIVA, LIMITE, FECHAHORA, SUSPENDIDO,
								PERMITEREG, CAJA, RAZON, DIRECCION, SODEXOACTIVO, pais,
								estado, ciudad, tipoc, Codigoc, 0, GRUPOC
							FROM BDES_POS.dbo.DBVENTAS_TMP WHERE IDTR = $params[0];

							INSERT INTO BDES_POS.dbo.ESVENTAS_TMP_DET
								(IDTR, LINEA, ARTICULO, BARRA, PRECIO, COSTO, CANTIDAD,
								 SUBTOTAL, IMPUESTO, PORC, PRECIOREAL, PROMO, PROMODSCTO,
								 IMAI, NDEREL)
							SELECT IDTR, LINEA, ARTICULO, BARRA, PRECIO, COSTO, CANTIDAD,
								 SUBTOTAL, IMPUESTO, PORC, PRECIOREAL, PROMO, PROMODSCTO, 
								 IMAI, NDEREL
							FROM BDES_POS.dbo.DBVENTAS_TMP_DET WHERE IDTR = $params[0] AND IMAI = 1";

					$sql = $connec->query($sql);
				}

				if($sql) {
					echo '1';
				} else {
					echo '0';
					print_r( $connec->errorInfo() );
				}
				break;

			case 'monitorlistaDocsweb':
				$sql = "UPDATE BDES_POS.dbo.DBVENTAS_TMP
						SET GRUPOC = 3
						WHERE IDTR NOT IN(SELECT IDTR FROM BDES_POS.dbo.ESVENTAS_TMP)
						AND GRUPOC = 2";

				$sql = $connec->query($sql);

				$sql = "SELECT IDTR,
						(SELECT TOP (1) [TELEFONO] FROM [BDES_POS].[dbo].[ESCLIENTESPOS] WHERE RIF LIKE '%'+cab.IDCLIENTE+'%' ORDER BY TELEFONO DESC) AS TELEFONO,
						(CONVERT(VARCHAR(10), FECHAHORA, 105)+' '+CONVERT(VARCHAR(5), FECHAHORA, 108)) AS FECHAHORA,
						(CASE WHEN FECHA_PICKING IS NULL THEN '' ELSE 
						(CONVERT(VARCHAR(10), FECHA_PICKING, 105)+' '+CONVERT(VARCHAR(5), FECHA_PICKING, 108)) END) AS FECHA_PICKING,
						(CASE WHEN FECHA_PROCESADO IS NULL THEN '' ELSE 
						(CONVERT(VARCHAR(10), FECHA_PROCESADO, 105)+' '+CONVERT(VARCHAR(5), FECHA_PROCESADO, 108)) END) AS FECHA_PROCESADO,
						RAZON, GRUPOC, CREADO_POR, PICKING_POR, PROCESADO_POR, paymentStatus AS FPAGO, paymentModule AS TPAGO,
						(SELECT ROUND(SUM((PRECIOOFERTA*(1+(PORC/100)))*CANTIDAD),2) FROM BDES_POS.dbo.DBVENTAS_TMP_DET WHERE IDTR = cab.IDTR) AS total,
						montodomicilio, descuentos
						FROM BDES_POS.dbo.DBVENTAS_TMP AS cab
						WHERE GRUPOC != 3
						ORDER BY GRUPOC";

				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$pedidopor = '';
					$pickingpor = '';
					$procesadopor = '';
					if($row['GRUPOC']==0) {
						$pedidopor = '<i class="fas fa-donate fa-2x mr-1 mt-1 mb-auto float-left"></i>'.
									 '<span class="mbadge m-0 p-0 text-left"><b>Pedido x: </b><br>'.$row['CREADO_POR'].'</span>';
					}
					if($row['GRUPOC']==1) {
						$pickingpor = '<i class="fas fa-cart-arrow-down fa-2x mr-1 mt-auto mb-auto float-left"></i>'.
									  '<span class="mbadge m-0 p-0 text-left"><b>Picking x: </b><br>'.$row['PICKING_POR'].'</span>';
					}
					if($row['GRUPOC']==2) {
						$procesadopor = '<i class="fas fa-cash-register fa-2x mr-1 mt-auto mb-auto float-left"></i>'.
										'<span class="mbadge m-0 p-0 text-left"><b>Procesado x: </b><br>'.$row['PROCESADO_POR'].'</span>';
					}
					$datos[] = [
						'nrodoc'         => $row['IDTR'],
						'nombre'  		 => $row['RAZON'].'<br><i class="fas fa-phone"></i> '.$row['TELEFONO'],
						'grupoc'         => $row['GRUPOC'],
						'fechapedido'    => $row['FECHAHORA'],
						'fechapicking'   => $row['FECHA_PICKING'],
						'fechaprocesado' => $row['FECHA_PROCESADO'],
						'pedidopor'      => $pedidopor,
						'pickingpor'     => $pickingpor,
						'procesadopor'   => $procesadopor,
						'fpago'          => ($row['FPAGO']==20?
												'<img height="100%" class="drop" src="dist/img/globalpay.png" title="'.$row['TPAGO'].'">':
												($row['FPAGO']==18?
													'<img height="100%" class="drop" src="dist/img/datafono.png" title="'.$row['TPAGO'].'">':
													'<img height="100%" class="drop" src="dist/img/monedas.png" title="'.$row['TPAGO'].'">')
											),
						'monto'			 => $row['total']+$row['montodomicilio']-$row['descuentos'],
					];
				}

				echo json_encode(array('data' => $datos));
				break;

			case 'lstPrefacturas':
				$idpara = explode(',', $idpara);
				$sql = "SELECT IDTR, IDCLIENTE, RAZON, GRUPOC, FECHA_PROCESADO, PROCESADO_POR,
						(CONVERT(VARCHAR(10), FECHAHORA, 105)+' '+CONVERT(VARCHAR(5), FECHAHORA, 108)) AS FECHAHORA,
						(SELECT COUNT(IDTR) FROM BDES_POS.dbo.DBVENTAS_TMP_DET
							WHERE IMAI = 1 AND IDTR = cab.IDTR) AS items,
						(SELECT SUM(PEDIDO) FROM BDES_POS.dbo.DBVENTAS_TMP_DET
							WHERE IMAI = 1 AND IDTR = cab.IDTR) AS pedidos,
						(SELECT SUM(CANTIDAD) FROM BDES_POS.dbo.DBVENTAS_TMP_DET
							WHERE IMAI = 1 AND IDTR = cab.IDTR) AS unidades,
						(SELECT SUM(ROUND((PRECIOOFERTA*(1+(PORC/100)))*CANTIDAD, 2)) FROM BDES_POS.dbo.DBVENTAS_TMP_DET
							WHERE IMAI = 1 AND IDTR = cab.IDTR) AS total
						FROM BDES_POS.dbo.DBVENTAS_TMP cab
						WHERE GRUPOC = 3 AND CAST(FECHAHORA AS DATE) BETWEEN '$idpara[0]' AND '$idpara[1]'
						ORDER BY GRUPOC";

				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$xfactura = '<div class="d-flex w-100 align-items-center">';
					$xfactura.= '<div style="width: 20%"><i class="fas fa-cash-register fa-2x"></i></div>';
					$xfactura.= '<div style="width: 80%" class="mbadge">'.$row['PROCESADO_POR'].'</div>';
					$xfactura.= '</div';
					$xfactura = ($row['GRUPOC']==1) ? $xfactura : '';
					$datos[] = [
						'nrodoc'   => $row['IDTR'],
						'fecha'    => date('d-m-Y H:i', strtotime($row['FECHAHORA'])),
						'rif'      => $row['IDCLIENTE'],
						'nombre'   => '<span class="btn-link p-0 m-0" style="cursor: pointer"'.
									 '   onclick="verDetalle('.$row['IDTR'].')" title="Ver Prefactura">'.
										 $row['RAZON'].
									 ' </span>',
						'items'    => $row['items'],
						'pedidos'  => $row['pedidos'],
						'unidades' => $row['unidades'],
						'total'    => $row['total'],
					];
				}

				echo json_encode(array('data' => $datos));
				break;

			case 'datosPreFactura':
				// Se crea el query para obtener los datos
				$sql = "SELECT cab.IDTR, 
							(CONVERT(VARCHAR(10), cab.FECHAHORA, 105)+' '+CONVERT(VARCHAR(5), cab.FECHAHORA, 108)) AS FECHA,
							cli.RAZON, cli.DIRECCION, cli.TELEFONO, cli.EMAIL, cab.IDCLIENTE, det.ARTICULO AS material,
							art.descripcion AS ARTICULO, det.PEDIDO, det.CANTIDAD, ROUND(det.PORC, 0) AS PORC,
							ROUND((det.PRECIOOFERTA*(1+(det.PORC/100))), 2) AS PRECIO,
							ROUND((det.PRECIOOFERTA*(1+(det.PORC/100)))*det.CANTIDAD, 2) AS TOTAL,
							(det.PRECIOOFERTA*det.CANTIDAD) AS SUBTOTAL, (det.COSTO*det.CANTIDAD) AS COSTO
						FROM BDES_POS.dbo.DBVENTAS_TMP AS cab
							INNER JOIN BDES_POS.dbo.ESCLIENTESPOS cli ON cli.RIF = cab.IDCLIENTE
							INNER JOIN BDES_POS.dbo.DBVENTAS_TMP_DET det ON det.IDTR = cab.IDTR
							INNER JOIN BDES.dbo.ESARTICULOS art ON art.codigo = det.ARTICULO
						WHERE det.IMAI = 1 AND cab.IDTR = $idpara
						ORDER BY det.LINEA";

				// Se ejecuta la consulta en la BBDD
				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());
				// Se prepara el array para almacenar los datos obtenidos
				$datos= [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datos[] = [
						'nrodoc'      => $row['IDTR'],
						'fecha'       => date('d-m-Y', strtotime($row['FECHA'])),
						'hora'        => date('h:i a', strtotime($row['FECHA'])),
						'razon'       => $row['RAZON'],
						'direccion'   => ucwords(strtolower(substr($row['DIRECCION'], 0, 100))),
						'telefono'    => $row['TELEFONO'],
						'email'       => $row['EMAIL'],
						'cliente'     => $row['IDCLIENTE'],
						'material'    => $row['material'],
						'descripcion' => '<span title="'.$row['material'].'">'.$row['ARTICULO'].'</span>',
						'pedido'      => $row['PEDIDO']*1,
						'cantidad'    => $row['CANTIDAD']*1,
						'precio'      => $row['PRECIO']*1,
						'impuesto'    => $row['PORC']*1,
						'total'       => $row['TOTAL']*1,
						'subtotal'    => $row['SUBTOTAL']*1,
						'costo'       => $row['COSTO']*1,
					];
				}

				// Se retornan los datos obtenidos
				echo json_encode($datos);
				break;

			case 'dptosWeb':
				$sql = "SELECT id, descripcion, activo FROM BDES.dbo.DB_DEPARTAMENTOS_WEB";
			
				$sql = $connec->query($sql);
			
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datos[] = [
						'id'          => $row['id'],
						'descripcion' => $row['descripcion'],
						'activo'      => $row['activo'],
					];
				}
				
				echo json_encode(array('data' => $datos));
				break;
			
			case 'guardarDpto':
				$sql = "INSERT INTO BDES.dbo.DB_DEPARTAMENTOS_WEB(descripcion)
						VALUES('$idpara')";

				$sql = $connec->query($sql);
			
				if(!$sql) {
					if($connec->errorInfo()[0]==23000) {
						echo 2;
					} else {
						echo 0;
						print_r($connec->errorInfo());
					}
				} else {
					echo 1;
				}
				
				break;
			
			case 'activarDpto':
				// se extraen los valores del parametro idpara
				$params = explode('¬', $idpara);

				$sql = "UPDATE BDES.dbo.DB_DEPARTAMENTOS_WEB SET
						activo = $params[1]
						WHERE id = $params[0]";
			
				$sql = $connec->query($sql);

				if($sql) {
					$sql = "UPDATE BDES.dbo.DB_GRUPOS_WEB SET
							activo = $params[1]
							WHERE departamento_web = $params[0]";
					$sql = $connec->query($sql);
					if($sql) {
						echo 1;
					} else {
						echo 0;
						print_r( $connec->errorInfo() );
					}
				} else {
					echo 0;
					print_r( $connec->errorInfo() );
				}
				break;			

			case 'modDescDpto':
				// se extraen los valores del parametro idpara
				$params = explode('¬', $idpara);

				$sql = "UPDATE BDES.dbo.DB_DEPARTAMENTOS_WEB SET
						descripcion = '$params[1]'
						WHERE id = $params[0]";
			
				$sql = $connec->query($sql);
			
				if($sql) {
					echo 1;
				} else {
					echo 0;
					print_r( $connec->errorInfo() );
				}
				
				break;
				
			case 'gruposWeb':
				$sql = "SELECT g.id, g.descripcion AS grupo,
							g.departamento_web AS dpto_id,
							d.descripcion AS departamento, g.activo
						FROM BDES.dbo.DB_GRUPOS_WEB g
						INNER JOIN BDES.dbo.DB_DEPARTAMENTOS_WEB d
							ON d.id = g.departamento_web";
			
				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datos[] = [
						'id'           => $row['id'],
						'grupo'        => $row['grupo'],
						'dpto_id'      => $row['dpto_id'],
						'departamento' => $row['departamento'],
						'activo'       => $row['activo'],
					];
				}
				
				echo json_encode(array('data' => $datos));
				break;
			
			case 'guardarGrupo':
				// se extraen los valores del parametro idpara
				$params = explode('¬', $idpara);

				$sql = "INSERT INTO BDES.dbo.DB_GRUPOS_WEB(descripcion, departamento_web)
						VALUES('$params[0]', $params[1])";

				$sql = $connec->query($sql);
			
				if(!$sql) {
					if($connec->errorInfo()[0]==23000) {
						echo 2;
					} else {
						echo 0;
						print_r($connec->errorInfo());
					}
				} else {
					echo 1;
				}
				
				break;
			
			case 'activarGrupo':
				// se extraen los valores del parametro idpara
				$params = explode('¬', $idpara);

				$sql = "UPDATE BDES.dbo.DB_GRUPOS_WEB SET
						activo = $params[1]
						WHERE id = $params[0]";
			
				$sql = $connec->query($sql);

				if($sql) {
					echo 1;
				} else {
					echo 0;
					print_r($connec->errorInfo());
				}
				break;			

			case 'modGrupo':
				// se extraen los valores del parametro idpara
				$params = explode('¬', $idpara);

				$sql = "UPDATE BDES.dbo.DB_GRUPOS_WEB SET
						descripcion = '$params[1]',
						departamento_web = $params[2]
						WHERE id = $params[0]";
			
				$sql = $connec->query($sql);
			
				if($sql) {
					echo 1;
				} else {
					echo 0;
					print_r( $connec->errorInfo() );
				}
				break;
				
			case 'listArtWeb':
				$sql = "SELECT art.codigo, art.descripcion, art.cant_minima, art.activo,
							COALESCE(dpto.descripcion, '') AS departamento,
							COALESCE(grupo.descripcion, '') AS grupo,
							COALESCE(bar.barra, '') AS barrafoto,
							art.Dpto, art.Grupo
						FROM BDES.dbo.ESARTICULOS_WEB art
						INNER JOIN BDES.dbo.DB_DEPARTAMENTOS_WEB dpto ON dpto.id = art.Dpto
						INNER JOIN BDES.dbo.DB_GRUPOS_WEB grupo ON grupo.id = art.Grupo
						INNER JOIN (SELECT escodigo, MAX(DISTINCT barra) AS barra
									FROM BDES.dbo.ESCodigos
									WHERE CAST(escodigo AS VARCHAR) != barra 
									GROUP BY escodigo) cod ON cod.escodigo = art.codigo
						INNER JOIN BDES.dbo.DBArticulosVirtualesBarraFotos bar ON bar.Barra = cod.barra";
			
				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datos[] = [
						'codigo'       => $row['codigo'],
						'descripcion'  => $row['descripcion'],
						'cant_minima'  => $row['cant_minima'],
						'activo'       => $row['activo'],
						'departamento' => $row['departamento'],
						'grupo'        => $row['grupo'],
						'barrafoto'    => $row['barrafoto'],
						'dpto_id'      => $row['Dpto'],
						'grupo_id'     => $row['Grupo'],
					];
				}
				
				echo json_encode(array('data' => $datos));
				break;
			
			case 'activarArtWeb':
				// se extraen los valores del parametro idpara
				$params = explode('¬', $idpara);

				$sql = "UPDATE BDES.dbo.ESARTICULOS_WEB SET
						activo = $params[1]
						WHERE codigo = $params[0]";
			
				$sql = $connec->query($sql);

				if($sql) {
					echo 1;
				} else {
					echo 0;
					print_r($connec->errorInfo());
				}
				break;

			case 'listPadreComptos':
				extract($_POST);
				$buscar = ($buscaren==1) ? "AND (art.codigo = $idpara OR bar.barra = '$idpara')" :
										  "AND LOWER(art.descripcion) LIKE LOWER('%$idpara%')";
				$sql = "SELECT DISTINCT art.codigo, COALESCE(bar.barra, '') AS barra, art.descripcion,
							COALESCE((bkp.Cantidad-bkp.comprometida-bkp.usada), 0) AS exist_padre,
							(CASE WHEN ac.codigo_padre IS NOT NULL THEN 1 ELSE 0 END) AS artpadre,
							COALESCE(ac.PorcInv_Padre, 0) AS porcinv_padre, art.costo,
							(art.PRECIO1*(1+(art.impuesto/100))) AS  precio
						FROM BDES.dbo.ESARTICULOS art
						LEFT JOIN (SELECT escodigo, MAX(DISTINCT barra) AS barra
							FROM BDES.dbo.ESCodigos
							WHERE CAST(escodigo AS VARCHAR) != barra 
							GROUP BY escodigo) AS bar ON bar.escodigo = art.codigo
						LEFT JOIN BDES.dbo.BiKardexExistencias bkp ON bkp.articulo=art.codigo AND bkp.almacen=1201
						LEFT JOIN BDES.dbo.DBArticulosCompuestos ac ON ac.codigo_padre=art.codigo AND ac.eliminado=0
						WHERE art.precio1>0 AND art.activo=1
						AND art.codigo NOT IN(SELECT codigo_hijo FROM BDES.dbo.DBArticulosCompuestos) ".$buscar;
			
				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$txt = '<button type="button" title="Agregar Artículo" onclick="' .
								" addarticulo('" . $row['codigo'] . "','" . $row['barra'] . 
									"','" . $row['descripcion'] . "'," . round($row['exist_padre']*1,3) .
									"," . $row['porcinv_padre']*1 . "," . $row['costo']*1 . "," . $row['precio']*1 . ")" .
								'" class="btn btn-link m-0 p-0 text-left font-weight-bold" ' .
								' style="white-space: normal; line-height: 1;">' . ucwords($row['descripcion']) .
							'</button>';
					$decimales = $row['exist_padre'] - intval($row['exist_padre']);
					$enteros = $row['exist_padre'] - $decimales;
					$exist_padre = number_format($enteros, 0).'.<sub>'.substr(number_format($decimales, 3), 2).'</sub>';
					$datos[] = [
						'codigo'        => $row['codigo'],
						'barra'         => $row['barra'],
						'descripcion'   => $txt,
						'exist_padre'   => $exist_padre,
						'artpadre'      => $row['artpadre'],
						'porcinv_padre' => $row['porcinv_padre']*1,
						'descripcion2'  => $row['descripcion'],
						'exist_padre2'  => $row['exist_padre']*1,
						'costo'         => $row['costo'],
						'precio'        => number_format($row['precio']*1, 2)
					];
				}
				
				echo json_encode($datos);
				break;
			
			case 'listaHijos':
				$datos = [];
				if($idpara!='') {
					$sql = "SELECT codigo_hijo, art.descripcion, porcmerma, valorempaque,
								valormo, ac.cantidad, rentabilidad AS rent_hijo, eliminado, tipo,
								CAST(
									(CASE WHEN artp.precio1 = 0 THEN 0
									ELSE (round(((artp.precio1-artp.costo)/artp.precio1*100), 2))
									END) 
								AS NUMERIC(5,2)) AS rent_padre,
								(((artp.costo*ac.cantidad)*(1+(ac.PorcMerma/100)))+ac.ValorEmpaque+ac.ValorMO) AS costo_hijo,
								(CASE WHEN artp.precio1 = 0 THEN 0 ELSE
									(CASE WHEN ac.tipo = 0 THEN
										((((artp.costo*ac.cantidad)*(1+(ac.PorcMerma/100)))+ac.ValorEmpaque+ac.ValorMO)/
										(100-ROUND(((artp.precio1-artp.costo)/artp.precio1*100), 2))*100)
										*(1+(artp.impuesto/100))
									ELSE
										((((artp.costo*ac.cantidad)*(1+(ac.PorcMerma/100)))+ac.ValorEmpaque+ac.ValorMO)/
										(100-ac.Rentabilidad)*100)*(1+(art.impuesto/100))
									END)
								END) AS precio1_hijo
							FROM BDES.dbo.DBArticulosCompuestos ac
							INNER JOIN BDES.dbo.ESARTICULOS art ON art.codigo = ac.codigo_hijo
							INNER JOIN BDES.dbo.ESARTICULOS artp ON artp.codigo = ac.codigo_padre
							LEFT JOIN BDES.dbo.BiKardexExistencias bkp ON bkp.articulo = ac.codigo_padre
								AND bkp.almacen = 1201
							WHERE ac.codigo_padre = $idpara";
				
					$sql = $connec->query($sql);
					if(!$sql) print_r($connec->errorInfo());
					
					while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
						$datos[] = [
							'codigo'       => $row['codigo_hijo'],
							'descripcion'  => $row['descripcion'],
							'merma'        => $row['porcmerma']*1,
							'relacion'     => $row['cantidad']*1,
							'empaque'      => $row['valorempaque']*1,
							'operativo'    => $row['valormo']*1,
							'rent_hijo'    => $row['rent_hijo']*1,
							'eliminado'    => $row['eliminado']*1,
							'tipo'         => $row['tipo']*1,
							'rent_padre'   => $row['rent_padre']*1,
							'preciohijo'   => number_format($row['precio1_hijo']*1, 2),
							'costohijo'    => number_format($row['costo_hijo']*1, 2)
						];
					}
				}

				echo json_encode(array('data' => $datos));
				break;
			
			case 'eliminarHijo':
				$idpara = explode('¬', $idpara);
				$sql = "UPDATE BDES.dbo.DBArticulosCompuestos SET
						eliminado = $idpara[1]
						WHERE codigo_padre = $idpara[2]
						AND codigo_hijo = $idpara[0]";
			
				$sql = $connec->query($sql);
				if(!$sql) {
					echo 0;
					print_r($connec->errorInfo());
				} else {
					echo 1;
				}
				break;
			
			case 'deleteHijo':
				extract($_POST);
				$sql = "DELETE FROM BDES.dbo.DBArticulosCompuestos
						WHERE codigo_padre = $padre AND codigo_hijo = $hijo";
			
				$sql = $connec->query($sql);
				if(!$sql)  {
					echo 0;
					print_r($connec->errorInfo());
				} else {
					$sql = "SELECT COUNT(*) AS cuenta FROM BDES.dbo.DBArticulosCompuestos
							WHERE codigo_padre = $padre";
					$sql = $connec->query($sql);
					$row = $sql->fetch(\PDO::FETCH_ASSOC);
					if($row['cuenta']==0) {
						echo 2;
					} else {
						echo 1;
					}
				}

				break;
			
			case 'guardarPadre':
				extract($_POST);
				$sql = "IF EXISTS(SELECT * FROM BDES.dbo.DBArticulosCompuestos
								  WHERE codigo_padre = $padre)
						BEGIN 
							UPDATE BDES.dbo.DBArticulosCompuestos SET
								PorcInv_Padre = $pinvp
							WHERE codigo_padre = $padre
						END";
			
				$sql = $connec->query($sql);
				if(!$sql) {
					echo 0;
					print_r($connec->errorInfo());
				} else {
					echo 1;
				}
				break;
			
			case 'guardarHijo':
				extract($_POST);
				$sql = "UPDATE BDES.dbo.DBArticulosCompuestos SET
							PorcMerma = $merma,
							ValorEmpaque = $empaq,
							ValorMO = $opera,
							Cantidad = $relac,
							Tipo = $tipo,
							Rentabilidad = $renta
						WHERE codigo_padre = $padre
						AND codigo_hijo = $idpara ";
			
				$sql = $connec->query($sql);
				if(!$sql) {
					echo 0;
					print_r($connec->errorInfo());
				} else {
					echo 1;
				}
				break;

			case 'agregarHijo':
				extract($_POST);
				$sql = "INSERT INTO BDES.dbo.DBArticulosCompuestos
						VALUES($padre, $pinvp, 1, $hijo, $merma, $empaq, $opera, $tipo, $relac, $renta, 0)";
			
				$sql = $connec->query($sql);
				if(!$sql) {
					if($connec->errorInfo()[0]==23000) {
						echo 2;
					} else {
						echo 0;
						print_r($connec->errorInfo());
					}
				} else {
					echo 1;
				}
				break;
			
			case 'lstHijos':
				$sql = "SELECT codigo, descripcion
						FROM BDES.dbo.ESARTICULOS
						WHERE activo = 1
						AND (lower(descripcion) LIKE lower('%$idpara%') OR
							codigo LIKE '%$idpara%')
						AND codigo NOT IN(SELECT codigo_padre FROM
									  BDES.dbo.DBArticulosCompuestos
									  UNION
									  SELECT codigo_hijo FROM
									  BDES.dbo.DBArticulosCompuestos)";
			
				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$txt = '<button type="button" title="Agregar Artículo" onclick="' .
								" addHijo('" . $row['codigo'] . "', '" . $row['descripcion'] . "')" .
								'" class="btn btn-link m-0 p-0 text-left font-weight-bold" ' .
								' style="white-space: normal; line-height: 1;">' . ucwords($row['descripcion']) .
							'</button>';
					$datos[] = [
						'codigo'      => $row['codigo'],
						'descripcion' => $txt,
					];
				}
				
				echo json_encode($datos);
				break;
			
			case 'listaCompuestos':
				$sql = "SELECT DISTINCT codigo_padre AS codpadre, artp.descripcion AS despadre,
							COALESCE((bkp.cantidad-bkp.comprometida-bkp.usada), 0) AS exipadre,
							ac.PorcInv_Padre AS porhijos, artp.costo AS cospadre,
							COALESCE((bkp.cantidad-bkp.comprometida-bkp.usada)*
								(ac.PorcInv_Padre/100), 0) AS dishijos,
							(CASE WHEN artp.precio1 = 0 THEN 0
							ELSE (artp.precio1*(1+(artp.impuesto/100))) END) AS pvppadre
						FROM BDES.dbo.DBArticulosCompuestos AS ac
						LEFT JOIN BDES.dbo.ESARTICULOS AS artp ON artp.codigo = ac.codigo_padre
						LEFT JOIN BDES.dbo.BIKardexExistencias AS bkp ON
							bkp.articulo = ac.codigo_padre AND almacen = 1201
						WHERE eliminado = 0";
			
				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());
			
				$datospadre = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datospadre[] = [
						'padre'     => 1,
						'codpadre'  => $row['codpadre'],
						'despadre'  => $row['despadre'],
						'exipadre'  => number_format($row['exipadre']*1, 2),
						'porhijos'  => number_format($row['porhijos']*1, 2),
						'dishijos'  => number_format($row['dishijos']*1, 2),
						'cospadre'  => number_format($row['cospadre']*1, 2),
						'pvppadre'  => number_format($row['pvppadre']*1, 2),
						'codhijo'   => '',
						'deshijo'   => '',
						'canthijo'  => '',
						'vempaque'  => '',
						'vmanoo'    => '',
						'mermahijo' => '',
						'costohijo' => '',
						'margen'    => '',
						'pvphijo'   => '',
					];
				}

				$sql = "SELECT hc.codpadre, hc.codhijo, hc.deshijo, hc.canthijo, hc.vempaque,
							hc.vmanoo, hc.mermahijo,
							(((artp.costo*hc.canthijo)*(1+(hc.mermahijo/100)))+hc.vempaque+hc.vmanoo) AS costohijo,
							(CASE WHEN hc.tipo = 0 THEN 
								(CASE WHEN artp.precio1 = 0 THEN 0 ELSE
									(ROUND(((artp.precio1-artp.costo)/artp.precio1*100), 2))
								END)
							ELSE
								hc.Rentabilidad
							END) AS margen,
							(CASE WHEN artp.precio1 = 0 THEN 0 ELSE
								(CASE WHEN hc.tipo = 0 THEN
									((((artp.costo*hc.canthijo)*(1+(hc.mermahijo/100)))+hc.vempaque+hc.vmanoo)/
									(100-ROUND(((artp.precio1-artp.costo)/artp.precio1*100), 2))*100)
									*(1+(artp.impuesto/100))
								ELSE
									((((artp.costo*hc.canthijo)*(1+(hc.mermahijo/100)))+hc.vempaque+hc.vmanoo)/
									(100-hc.Rentabilidad)*100)*(1+(artp.impuesto/100))
								END)
							END) AS pvphijo
						FROM
							(SELECT codigo_padre AS codpadre, codigo_hijo AS codhijo, arth.descripcion AS deshijo,
								ac.PorcMerma AS mermahijo, ac.Cantidad AS canthijo, ac.ValorEmpaque AS vempaque,
								ac.ValorMO AS vmanoo, ac.tipo, ac.Rentabilidad
							FROM BDES.dbo.DBArticulosCompuestos AS ac
							LEFT JOIN BDES.dbo.ESARTICULOS AS arth ON arth.codigo = ac.codigo_hijo
							LEFT JOIN BDES.dbo.BIKardexExistencias AS bkh ON
								bkh.articulo = ac.codigo_hijo AND almacen = 1201
							WHERE eliminado = 0) AS hc
						LEFT JOIN BDES.dbo.ESARTICULOS AS artp ON artp.codigo = hc.codpadre";
			
				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());

				$datoshijos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datoshijos[] = [
						'padre'     => 2,
						'codpadre'  => $row['codpadre'],
						'despadre'  => '',
						'exipadre'  => '',
						'porhijos'  => '',
						'dishijos'  => '',
						'cospadre'  => '',
						'pvppadre'  => '',
						'codhijo'   => $row['codhijo'],
						'deshijo'   => $row['deshijo'],
						'canthijo'  => number_format($row['canthijo']*1, 2),
						'vempaque'  => number_format($row['vempaque']*1, 2),
						'vmanoo'    => number_format($row['vmanoo']*1, 2),
						'mermahijo' => number_format($row['mermahijo']*1, 0),
						'costohijo' => number_format($row['costohijo']*1, 2),
						'margen'    => number_format($row['margen']*1, 2),
						'pvphijo'   => number_format($row['pvphijo']*1, 2),
					];
				}

				$datos = array_merge($datospadre, $datoshijos);

				foreach ($datos as $clave => $fila) {
					$orden1[$clave] = $fila['codpadre'];
					$orden2[$clave] = $fila['padre'];
				}

				array_multisort($orden1, SORT_ASC, $orden2, SORT_ASC, $datos);
				
				echo json_encode($datos);
				break;
			
			case 'exportCompuestos':
				// Se prepara el query para obtener los datos
				$sql = "SELECT hc.codpadre, hc.despadre, hc.exipadre, (hc.porhijos/100) AS porhijos, hc.disphijos,
							hc.costopadre, (hc.margenpadre/100) AS margenpadre, hc.pvppadre,
							(hc.impuesto/100) AS impuesto,
							hc.codhijo, hc.deshijo, hc.canthijo, hc.vempaque, hc.vmanoo,
							(hc.mermahijo/100) AS mermahijo,
							(((artp.costo*hc.canthijo)*(1+(hc.mermahijo/100)))+hc.vempaque+hc.vmanoo) AS costohijo,
							(hc.Rentabilidad/100) AS margenhijo,
							(CASE WHEN hc.tipo = 1 THEN 'Margen Hijo' ELSE 'Margen Padre' END) AS tipomargen,
							(CASE WHEN artp.precio1 = 0 THEN 0 ELSE
								(CASE WHEN hc.tipo = 0 THEN
									((((artp.costo*hc.canthijo)*(1+(hc.mermahijo/100)))+hc.vempaque+hc.vmanoo)/
									(100-ROUND(((artp.precio1-artp.costo)/artp.precio1*100), 2))*100)
									*(1+(artp.impuesto/100))
								ELSE
									((((artp.costo*hc.canthijo)*(1+(hc.mermahijo/100)))+hc.vempaque+hc.vmanoo)/
									(100-hc.Rentabilidad)*100)*(1+(artp.impuesto/100))
								END)
							END) AS pvphijo
						FROM
							(SELECT
								codigo_padre AS codpadre, artp.descripcion AS despadre,
								COALESCE((bkp.cantidad-bkp.comprometida-bkp.usada), 0) AS exipadre,
								ac.PorcInv_Padre AS porhijos, artp.impuesto AS impuesto,
								COALESCE((bkp.cantidad-bkp.comprometida-bkp.usada)*
									(ac.PorcInv_Padre/100), 0) AS disphijos,
								(CASE WHEN artp.precio1 = 0 THEN 0 ELSE
									ROUND(((artp.precio1-artp.costo)/artp.precio1*100), 2) END) margenpadre,
								artp.costo AS costopadre,
								(CASE WHEN artp.precio1 = 0 THEN 0
									ELSE (artp.precio1*(1+(artp.impuesto/100)))
								END) AS pvppadre,
								codigo_hijo AS codhijo, arth.descripcion AS deshijo,
								ac.PorcMerma AS mermahijo, ac.Cantidad AS canthijo, ac.ValorEmpaque AS vempaque,
								ac.ValorMO AS vmanoo,	ac.tipo, ac.Rentabilidad
							FROM BDES.dbo.DBArticulosCompuestos AS ac
							LEFT JOIN BDES.dbo.ESARTICULOS AS arth ON arth.codigo = ac.codigo_hijo
							LEFT JOIN BDES.dbo.ESARTICULOS AS artp ON artp.codigo = ac.codigo_padre
							LEFT JOIN BDES.dbo.BIKardexExistencias AS bkh ON bkh.articulo = ac.codigo_hijo
								AND bkh.almacen = 1201
							LEFT JOIN BDES.dbo.BIKardexExistencias AS bkp ON bkp.articulo = ac.codigo_padre
								AND bkp.almacen = 1201) AS hc
						LEFT JOIN BDES.dbo.ESARTICULOS AS artp ON artp.codigo = hc.codpadre";

				// Ejecutar connsulta en BBDD
				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());

				$fecha1 = date('d/m/Y', strtotime($fecha));

				require_once "../Classes/PHPExcel.php";
				require_once "../Classes/PHPExcel/Writer/Excel5.php"; 

				$objPHPExcel = new PHPExcel();

				// Set document properties
				$objPHPExcel->getProperties()
					->setCreator("Dashboard")
					->setLastModifiedBy("Dashboard")
					->setTitle("Reporte Articulos Compuestos CV ".$fecha1)
					->setSubject("Reporte Articulos Compuestos CV ".$fecha1)
					->setDescription("Reporte Articulos Compuestos CV ".$fecha1." generado usando el Dashboard.")
					->setKeywords("Office 2007 openxml php")
					->setCategory("Reporte Articulos Compuestos CV ".$fecha1);

				$objPHPExcel->setActiveSheetIndex(0);
				$icorr = date('dmy', strtotime($fecha));

				$objPHPExcel->getActiveSheet()
					->SetCellValue('A1',	'INFROMACIÓN DEL ARTÍCULO PADRE')
					->mergeCells('A1:I1')
					->getStyle('A1:I1')
					->getAlignment()
					->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);

				$objPHPExcel->getActiveSheet()
					->SetCellValue('J1',	'INFROMACIÓN DEL ARTÍCULO HIJO')
					->mergeCells('J1:S1')
					->getStyle('J1:S1')
					->getAlignment()
					->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);

				$objPHPExcel->getActiveSheet()
					->SetCellValue('A2', 'CÓDIGO')
					->SetCellValue('B2', 'DESCRIPCIÓN')
					->SetCellValue('C2', 'EXISTENCIA')
					->SetCellValue('D2', '%PARA HIJOS')
					->SetCellValue('E2', 'DISPONIBLE HIJOS')
					->SetCellValue('F2', 'COSTO')
					->SetCellValue('G2', 'MARGEN')
					->SetCellValue('H2', 'IMPUESTO')
					->SetCellValue('I2', 'PRECIO');

				$objPHPExcel->getActiveSheet()
					->SetCellValue('J2', 'CÓDIGO')
					->SetCellValue('K2', 'DESCRIPCIÓN')
					->SetCellValue('L2', 'CONVERSIÓN')
					->SetCellValue('M2', 'VALOR EMPAQUE')
					->SetCellValue('N2', 'VALOR MANO OBRA')
					->SetCellValue('O2', '%MERMA')
					->SetCellValue('P2', 'COSTO')
					->SetCellValue('Q2', 'MARGEN')
					->SetCellValue('R2', 'TIPO MARGEN')
					->SetCellValue('S2', 'PRECIO');

				$rowCount = 3;

				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$objPHPExcel->getActiveSheet()
						->SetCellValue('A'.$rowCount, $row['codpadre'])
						->SetCellValue('B'.$rowCount, $row['despadre'])
						->SetCellValue('C'.$rowCount, $row['exipadre'])
						->SetCellValue('D'.$rowCount, $row['porhijos'])
						->SetCellValue('E'.$rowCount, $row['disphijos'])
						->SetCellValue('F'.$rowCount, $row['costopadre'])
						->SetCellValue('G'.$rowCount, $row['margenpadre'])
						->SetCellValue('H'.$rowCount, $row['impuesto'])
						->SetCellValue('I'.$rowCount, $row['pvppadre'])
						->SetCellValue('J'.$rowCount, $row['codhijo'])
						->SetCellValue('K'.$rowCount, $row['deshijo'])
						->SetCellValue('L'.$rowCount, $row['canthijo'])
						->SetCellValue('M'.$rowCount, $row['vempaque'])
						->SetCellValue('N'.$rowCount, $row['vmanoo'])
						->SetCellValue('O'.$rowCount, $row['mermahijo'])
						->SetCellValue('P'.$rowCount, $row['costohijo'])
						->SetCellValue('Q'.$rowCount, $row['margenhijo'])
						->SetCellValue('R'.$rowCount, $row['tipomargen'])
						->SetCellValue('S'.$rowCount, $row['pvphijo']);

					$rowCount++;
				}

				$objPHPExcel->getActiveSheet()
					->getStyle('C3:C'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED3);

				$objPHPExcel->getActiveSheet()
					->getStyle('D3:D'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_PERCENTAGE_00);

				$objPHPExcel->getActiveSheet()
					->getStyle('E3:E'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED3);

				$objPHPExcel->getActiveSheet()
					->getStyle('F3:F'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

				$objPHPExcel->getActiveSheet()
					->getStyle('G3:H'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_PERCENTAGE_00);

				$objPHPExcel->getActiveSheet()
					->getStyle('I3:I'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

				$objPHPExcel->getActiveSheet()
					->getStyle('L3:L'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED3);

				$objPHPExcel->getActiveSheet()
					->getStyle('M3:N'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

				$objPHPExcel->getActiveSheet()
					->getStyle('O3:O'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_PERCENTAGE_00);

				$objPHPExcel->getActiveSheet()
					->getStyle('P3:P'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

				$objPHPExcel->getActiveSheet()
					->getStyle('Q3:Q'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_PERCENTAGE_00);

				$objPHPExcel->getActiveSheet()
					->getStyle('S3:S'.$rowCount)
					->getNumberFormat()
					->setFormatCode(PHPExcel_Style_NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

				$objPHPExcel->getActiveSheet()->getStyle('A1:S2')->getFont()->setBold(true);

				$objPHPExcel->getActiveSheet()->getStyle('A1:I2')
					->getFill()
					->setFillType(PHPExcel_Style_Fill::FILL_SOLID)
					->getStartColor()
					->setRGB('A2C8EB');

				$objPHPExcel->getActiveSheet()->getStyle('J1:S2')
					->getFill()
					->setFillType(PHPExcel_Style_Fill::FILL_SOLID)
					->getStartColor()
					->setRGB('A2EBCF');
				
				$objPHPExcel->getActiveSheet()->freezePane('A3');

				$rowCount--;				

				$objPHPExcel->getActiveSheet()->setAutoFilter('A2:S'.$rowCount);
				
				foreach (range('A', $objPHPExcel->getActiveSheet()->getHighestDataColumn()) as $col) {
					$objPHPExcel
						->getActiveSheet()
						->getColumnDimension($col)
						->setAutoSize(true);
				}

				$objPHPExcel->getActiveSheet()->setSelectedCell('A3');

				// Rename worksheet
				$objPHPExcel->getActiveSheet()->setTitle('Articulos Compuestos');
				// Set active sheet index to the first sheet, so Excel opens this as the first sheet
				$objPHPExcel->setActiveSheetIndex(0);

				// Redirect output to a client’s web browser (Excel5)
				header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
				header('Content-Disposition: attachment;filename="ArtCompstos.xls"');
				header('Cache-Control: max-age=0');
				// If you're serving to IE 9, then the following may be needed
				header('Cache-Control: max-age=1');

				// If you're serving to IE over SSL, then the following may be needed
				header ('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
				header ('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT'); // always modified
				header ('Cache-Control: cache, must-revalidate'); // HTTP/1.1
				header ('Pragma: public'); // HTTP/1.0

				$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');

				$objWriter->save('../tmp/ArtCompstos_'.$icorr.'.xls');

				echo json_encode(array('enlace'=>'tmp/ArtCompstos_'.$icorr.'.xls', 'archivo'=>'ArtCompstos_'.$icorr.'.xls'));
				break;

			case 'listaPedidos':
				extract($_POST);
				$sql = "SELECT tr.nro_pedido, cab.CREADO_POR, tr.fecha, UPPER(cli.RAZON) AS RAZON,
							cli.TELEFONO, tr.nombre_transporte, tr.placa_transporte, tr.datafono,
							tr.telefono_transporte, tr.fpago, tr.tpago, tr.status, tr.efectivo,
							tr.total
						FROM BDES_POS.dbo.DBInfoTransporte AS tr
						INNER JOIN BDES_POS.dbo.DBVENTAS_TMP AS cab ON cab.IDTR = tr.nro_pedido
						INNER JOIN BDES_POS.dbo.ESCLIENTESPOS cli ON cli.RIF = cab.IDCLIENTE
						INNER JOIN BDES.dbo.ESSucursales ti ON ti.codigo = tr.localidad
						WHERE tr.status = $idpara
						AND CAST(tr.fecha AS DATE) BETWEEN '$desde' AND '$hasta'";

				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());

				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$nroweb = '';
					if($row['tpago']>0) {
						$nroweb = substr($row['CREADO_POR'], strrpos($row['CREADO_POR'], ':')+1);
					}
					$fpago = $row['fpago'];
					$fpago.='<br><div class="d-flex">'; 
					$fpago.= '<div class="text-center col-4 bg-success p-0 pr-1 pl-1">'.
							 'Efec.Extra<br>' . number_format($row['efectivo'], 2) . '</div>';
					$fpago.= '<div class="text-center col-4 bg-warning p-0 pr-1 pl-1">'.
							 'Tot.Fact.<br>' . number_format($row['total'], 2) . '</div>';	
					$fpago.= '<div class="text-center col-4 bg-info p-0 pr-1 pl-1">'.
							 'Datafono<br>' . $row['datafono'] . '</div>';
					$fpago.= '</div>';

					$datos[] = [
						'nrodoc'   => $row['nro_pedido'],
						'nroweb'   => $nroweb,
						'nombre'   => $row['RAZON'].'<br>&#9742; '.$row['TELEFONO'],
						'fecha'    => date('d-m-Y H:i', strtotime($row['fecha'])),
						'nomtrans' => $row['nombre_transporte'].'<br>&#9873; '.
									  $row['placa_transporte'].'&emsp;&#9742; '.
									  $row['telefono_transporte'],
						'fpago'    => $fpago,
						'status'   => $row['status'],
						'efectivo' => $row['efectivo'],
						'datafono' => $row['datafono'],
					];
				}

				echo json_encode(array('data' => $datos));
				break;

			case 'cerrarEnvio':
				$sql = "UPDATE BDES_POS.dbo.DBInfoTransporte SET status=1 WHERE nro_pedido = $idpara";
			
				$sql = $connec->query($sql);
				if(!$sql) {
					echo '0¬'.$connec->errorInfo()[2];
				} else {
					if($sql->rowCount()==0) {
						echo '0¬No se modificó ningun registro.';
					} else {
						echo '1¬Modificado con éxito ('.$sql->rowCount().')';
					}
				}
				break;
			
			case 'listadoTrans':
				$sql = "SELECT * FROM BDES_POS.dbo.DBTranspDomicilios
						WHERE eliminado $idpara";
			
				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());
			
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datos[] = $row;
				}
				
				echo json_encode(array('data' => $datos));
				break;
			
			case 'nuevo_trans':
				extract($_POST);
				$sql = "INSERT INTO BDES_POS.dbo.DBTranspDomicilios
						(cedula, nombre, telefono, placa)
						VALUES
						($cedula, UPPER('$nombre'), '$telefo', UPPER('$placas'))";
			
				$sql = $connec->query($sql);
				if(!$sql) {
					if($connec->errorInfo()[0]==23000) {
						echo '0¬Información ya existe<br>Por favor verifique.';
					} else {
						echo '0¬Error no se pudo registrar la información<br>'.$connec->errorInfo()[2];
					}
				} else {
					echo '1¬Información registrada correctamente.';
				}
				break;

			case 'editar_trans':
				extract($_POST);
				$sql = "UPDATE BDES_POS.dbo.DBTranspDomicilios SET
							cedula   = $cedula,
							nombre   = UPPER('$nombre'),
							telefono = '$telefo',
							placa    = UPPER('$placas')
						WHERE id = $idpara";
			
				$sql = $connec->query($sql);
				if(!$sql) {
					echo '0¬Error no se pudo registrar la información<br>'.$connec->errorInfo()[2];
				} else {
					echo '1¬Información registrada correctamente.';
				}
				break;
			
			case 'eliminar_trans':
				extract($_POST);
				$sql = "UPDATE BDES_POS.dbo.DBTranspDomicilios SET
							eliminado = $status
						WHERE id = $idpara";
			
				$sql = $connec->query($sql);
				if(!$sql) {
					echo '0¬Error no se pudo registrar la información<br>'.$connec->errorInfo()[2];
				} else {
					echo '1¬Información registrada correctamente.';
				}
				break;
			
			case 'lstDrives':
				extract($_POST);
				$sql = "SELECT * FROM BDES_POS.dbo.DBInfoTransporte
						WHERE CAST(fecha AS DATE) BETWEEN '$fdesde' AND '$fhasta'";
			
				$sql = $connec->query($sql);
				if(!$sql) print_r($connec->errorInfo());
			
				$datos = [];
				while ($row = $sql->fetch(\PDO::FETCH_ASSOC)) {
					$datos[] = [
						'nrofac' => $row['factura'],
						'montof' => number_format($row['total'], 2),
						'dircte' => $row['direccion'],
						'fechad' => date('d-m-Y h:i a', strtotime($row['fecha'])),
						'driver' => $row['nombre_transporte'],
						'montod' => number_format($row['monto_domicilio'],2),
					];
				}
				
				echo json_encode(array('data'=>$datos));
				break;
			
			default:
				# code...
				break;
		}

		// Se cierra la conexion
		$connec = null;

	} catch (PDOException $e) {
		echo "Error : " . $e->getMessage() . "<br/>";
		die();
	}
?>